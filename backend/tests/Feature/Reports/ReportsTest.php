<?php

namespace Tests\Feature\Reports;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4: management and finance reports, the owner's dashboard and the CSV export.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $cairo;

    private Branch $faisal;

    private Treasury $cairoCash;

    private Treasury $faisalCash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cairo = Branch::factory()->create(['name' => 'فرع القاهرة']);
        $this->faisal = Branch::factory()->create(['name' => 'فيصل']);
        $this->cairoCash = Treasury::create(['branch_id' => $this->cairo->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->faisalCash = Treasury::create(['branch_id' => $this->faisal->id, 'currency_id' => 1, 'method' => 'cash']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ledgerPost(Treasury $treasury, string $direction, string $kind, string $amount, array $context = []): Payment
    {
        return app(LedgerService::class)->post($treasury, $direction, $kind, $amount, $context);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $report, string $query = ''): array
    {
        return $this->getJson("/api/v1/reports/{$report}{$query}")->assertOk()->json('data.rows');
    }

    public function test_treasury_balances_show_every_treasury_to_the_owner_and_only_the_branchs_own_to_its_staff(): void
    {
        $this->signInAs('owner');
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '1500.00');
        $this->ledgerPost($this->faisalCash, 'in', 'collection', '700.50');
        $this->ledgerPost($this->faisalCash, 'out', 'expense', '200.00');

        $all = $this->rows('treasury-balances');
        $this->assertSame(['فرع القاهرة' => '1500.00', 'فيصل' => '500.50'], collect($all)->pluck('balance', 'branch')->all());

        $this->signInAs('branch_staff', ['branch_id' => $this->cairo->id]);
        $own = $this->rows('treasury-balances');
        $this->assertCount(1, $own);
        $this->assertSame('فرع القاهرة', $own[0]['branch']);
    }

    public function test_branch_activity_adds_up_collections_refunds_and_expenses_inside_the_period(): void
    {
        $this->signInAs('owner');
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '1000.00');
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '500.00');
        $this->ledgerPost($this->cairoCash, 'out', 'refund', '200.00');
        $this->ledgerPost($this->cairoCash, 'out', 'expense', '100.00');
        $this->ledgerPost($this->cairoCash, 'in', 'agent_settlement', '300.00', ['payer_type' => 'agent']);
        $this->ledgerPost($this->faisalCash, 'in', 'collection', '50.00');

        $rows = collect($this->rows('branch-activity', '?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString()))->keyBy('branch');

        $this->assertSame('1500.00', $rows['فرع القاهرة']['collected']);
        $this->assertSame('200.00', $rows['فرع القاهرة']['refunded']);
        $this->assertSame('100.00', $rows['فرع القاهرة']['spent']);
        $this->assertSame('300.00', $rows['فرع القاهرة']['from_offices']);
        $this->assertSame('1500.00', $rows['فرع القاهرة']['net']);
        $this->assertSame('50.00', $rows['فيصل']['collected']);

        // A period that ended before the movements were made shows nothing.
        $this->assertSame([], $this->rows('branch-activity', '?from=2020-01-01&to=2020-01-31'));
    }

    public function test_cancellations_list_shipments_and_bookings_with_their_reasons(): void
    {
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'status' => 'cancelled', 'cancel_reason' => 'العميل غيّر رأيه', 'price' => '900.00', 'discount' => '100.00']);
        Shipment::factory()->create(['branch_id' => $this->faisal->id, 'status' => 'cancelled', 'cancel_reason' => 'سعر غلط']);
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'status' => 'approved']);
        Booking::factory()->create(['trip_id' => PassengerTrip::factory(), 'branch_id' => $this->cairo->id, 'status' => 'cancelled', 'cancel_reason' => 'اتأجل السفر']);

        $this->signInAs('owner');
        $all = $this->rows('cancellations');
        $this->assertCount(3, $all);
        $this->assertEqualsCanonicalizing(['العميل غيّر رأيه', 'سعر غلط', 'اتأجل السفر'], array_column($all, 'reason'));
        $this->assertSame('800.00', collect($all)->firstWhere('reason', 'العميل غيّر رأيه')['amount']);

        $this->signInAs('branch_staff', ['branch_id' => $this->cairo->id]);
        $own = $this->rows('cancellations');
        $this->assertCount(2, $own);
        $this->assertEqualsCanonicalizing(['شحنة', 'حجز مقعد'], array_column($own, 'type'));
    }

    public function test_refunds_and_staff_movements_are_grouped_by_who_made_them(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => $this->cairo->id, 'name' => 'سارة']);
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '400.00');
        $this->ledgerPost($this->cairoCash, 'out', 'refund', '150.00', ['notes' => 'إلغاء حجز']);
        $this->signInAs('branch_staff', ['branch_id' => $this->cairo->id, 'name' => 'عمر']);
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '100.00');

        $this->signInAs('owner');
        $refunds = $this->rows('refunds');
        $this->assertCount(1, $refunds);
        $this->assertSame('150.00', $refunds[0]['amount']);
        $this->assertSame('سارة', $refunds[0]['employee']);
        $this->assertSame('إلغاء حجز', $refunds[0]['notes']);

        $staff = collect($this->rows('staff-movements'))->keyBy('employee');
        $this->assertSame(2, $staff['سارة']['movements']);
        $this->assertSame('400.00', $staff['سارة']['money_in']);
        $this->assertSame('150.00', $staff['سارة']['money_out']);
        $this->assertSame(1, $staff['عمر']['movements']);
    }

    public function test_debts_list_customers_with_a_remaining_balance_and_skip_drafts_and_settled_shipments(): void
    {
        $debtor = Customer::factory()->create(['name' => 'سعد محمود', 'branch_id' => $this->cairo->id]);
        $settled = Customer::factory()->create(['name' => 'منى علي', 'branch_id' => $this->cairo->id]);
        $owing = Shipment::factory()->create(['branch_id' => $this->cairo->id, 'customer_id' => $debtor->id, 'status' => 'approved', 'price' => '1000.00', 'discount' => '100.00']);
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'customer_id' => $debtor->id, 'status' => 'approved', 'price' => '500.00']);
        $paid = Shipment::factory()->create(['branch_id' => $this->cairo->id, 'customer_id' => $settled->id, 'status' => 'approved', 'price' => '300.00']);
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'customer_id' => $settled->id, 'status' => 'draft', 'price' => '9999.00']);

        $this->signInAs('owner');
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '300.00', ['shipment_id' => $paid->id]);
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '200.00', ['shipment_id' => $owing->id]);

        $rows = $this->rows('debts');
        $this->assertCount(1, $rows);
        $this->assertSame('سعد محمود', $rows[0]['customer']);
        $this->assertSame(2, $rows[0]['shipments']);
        $this->assertSame('1400.00', $rows[0]['due']);
        $this->assertSame('200.00', $rows[0]['paid']);
        $this->assertSame('1200.00', $rows[0]['remaining']);

        $this->signInAs('branch_staff', ['branch_id' => $this->faisal->id]);
        $this->assertSame([], $this->rows('debts'));
    }

    public function test_the_agents_report_shows_every_office_to_the_company_and_an_office_only_itself(): void
    {
        $mine = Agent::factory()->create(['name' => 'مكتب النور']);
        $rival = Agent::factory()->create(['name' => 'مكتب الأمل']);
        foreach ([[$mine, 1, '2000.00'], [$rival, 2, '3000.00']] as $index => [$agent, $seat, $price]) {
            Booking::factory()->create([
                'trip_id' => PassengerTrip::factory(), 'seat_no' => $index + 1, 'agent_id' => $agent->id, 'agent_price' => $price, 'status' => 'reserved',
            ]);
        }

        $this->signInAs('owner');
        $this->assertSame(['مكتب الأمل', 'مكتب النور'], array_column($this->rows('agents'), 'agent'));

        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $mine->id]), 'sanctum');
        $own = $this->rows('agents');
        $this->assertCount(1, $own);
        $this->assertSame('مكتب النور', $own[0]['agent']);
        $this->assertSame('2000.00', $own[0]['remaining']);

        $this->signInAs('branch_staff', ['branch_id' => $this->cairo->id]);
        $this->getJson('/api/v1/reports/agents')->assertForbidden();
    }

    public function test_offices_and_users_without_the_permission_get_no_company_reports(): void
    {
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => Agent::factory()->create()->id]), 'sanctum');
        foreach (['treasury-balances', 'branch-activity', 'cancellations', 'refunds', 'staff-movements', 'debts'] as $report) {
            $this->getJson("/api/v1/reports/{$report}")->assertForbidden();
        }
        $this->getJson('/api/v1/reports/dashboard')->assertForbidden();

        $this->signInAs('faisal_staff');
        $this->getJson('/api/v1/reports/debts')->assertForbidden();
    }

    public function test_bad_requests_are_refused(): void
    {
        $this->signInAs('owner');

        $this->getJson('/api/v1/reports/nonsense')->assertNotFound();
        $this->getJson('/api/v1/reports/refunds?from=2026-02-01&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/reports/refunds?from=yesterday')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_the_dashboard_summarises_money_and_open_work(): void
    {
        $this->signInAs('owner');
        $this->ledgerPost($this->cairoCash, 'in', 'collection', '1000.00');
        $this->ledgerPost($this->faisalCash, 'in', 'collection', '250.00');
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'status' => 'at_branch']);
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'status' => 'at_branch']);
        Shipment::factory()->create(['branch_id' => $this->faisal->id, 'status' => 'loaded']);
        Shipment::factory()->create(['branch_id' => $this->faisal->id, 'status' => 'delivered']);
        PassengerTrip::factory()->create();

        $data = $this->getJson('/api/v1/reports/dashboard')->assertOk()->json('data');

        $this->assertSame('1250.00', $data['treasury_totals'][0]['amount']);
        $this->assertSame('1250.00', $data['collected_today'][0]['amount']);
        $this->assertSame(['at_branch' => 2, 'loaded' => 1], $data['open_shipments']);
        $this->assertSame(1, $data['passenger_trips_open']);
        $this->assertSame(0, $data['pending_remittances']);
        $this->assertSame(0, $data['agents_owing']);

        $this->signInAs('branch_staff', ['branch_id' => $this->faisal->id]);
        $own = $this->getJson('/api/v1/reports/dashboard')->assertOk()->json('data');
        $this->assertSame('250.00', $own['treasury_totals'][0]['amount']);
        $this->assertSame(['loaded' => 1], $own['open_shipments']);
        $this->assertNull($own['agents_owing']);
    }

    public function test_every_report_downloads_as_csv_that_excel_reads_and_that_cannot_inject_formulas(): void
    {
        $this->signInAs('owner');
        $debtor = Customer::factory()->create(['name' => '=HYPERLINK("http://x")', 'branch_id' => $this->cairo->id]);
        Shipment::factory()->create(['branch_id' => $this->cairo->id, 'customer_id' => $debtor->id, 'status' => 'approved', 'price' => '100.00']);

        $response = $this->get('/api/v1/reports/debts?format=csv')->assertOk();
        $body = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('العميل,التليفون,العملة,"عدد الشحنات",الإجمالي,المدفوع,المتبقي', $body);
        $this->assertStringContainsString("\"'=HYPERLINK", $body);
        $this->assertStringNotContainsString(',"=HYPERLINK', $body);
        $this->assertSame(1, DB::table('v_shipment_balances')->where('remaining', '>', 0)->count());
    }
}
