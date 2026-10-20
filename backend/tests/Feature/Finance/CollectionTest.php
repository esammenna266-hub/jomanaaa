<?php

namespace Tests\Feature\Finance;

use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    private User $staff;

    private int $shipmentId;

    private Treasury $egp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->egp = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->staff = User::factory()->withRole('branch_staff')->create(['branch_id' => $this->branch->id]);
        $this->shipmentId = $this->approvedShipment();
    }

    /**
     * An approved 1000 EGP shipment at this branch.
     */
    private function approvedShipment(string $price = '1000.00'): int
    {
        Sanctum::actingAs($this->staff);
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $id = $this->postJson('/api/v1/shipments', [
            'customer_id' => $customer->id,
            'consignee_name' => 'Mohamed',
            'consignee_phone' => '0912345678',
            'destination_id' => DB::table('destinations')->value('id'),
            'currency_id' => 1,
            'price' => $price,
            'items' => [['name' => 'Clothes', 'qty' => 10, 'unit' => 'carton']],
        ])->json('data.id');
        $this->postJson("/api/v1/shipments/{$id}/submit")->assertOk();

        $this->signInAs('owner');
        $approval = DB::table('approvals')->where('subject_id', $id)->value('id');
        $this->postJson("/api/v1/approvals/{$approval}/decide", ['decision' => 'approve'])->assertOk();

        Sanctum::actingAs($this->staff->fresh());

        return $id;
    }

    private function collect(string $amount, array $extra = [], ?Treasury $treasury = null)
    {
        return $this->postJson('/api/v1/payments', [
            'shipment_id' => $this->shipmentId,
            'treasury_id' => ($treasury ?? $this->egp)->id,
            'amount' => $amount,
            ...$extra,
        ]);
    }

    public function test_deposit_reduces_the_remaining_and_increases_the_treasury(): void
    {
        $this->collect('300.50')->assertCreated()
            ->assertJsonPath('data.direction', 'in')
            ->assertJsonPath('data.currency_id', 1)
            ->assertJsonPath('data.serial_no', '000001');

        $this->getJson("/api/v1/shipments/{$this->shipmentId}")
            ->assertJsonPath('data.balance.paid', '300.50')
            ->assertJsonPath('data.balance.remaining', '699.50');

        $this->getJson("/api/v1/treasuries/{$this->egp->id}")->assertOk()->assertJsonPath('data.balance', '300.50');
    }

    public function test_cannot_collect_more_than_the_remaining_or_before_approval(): void
    {
        $this->collect('1000.01')->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->collect('1000')->assertCreated();
        $this->collect('0.01')->assertUnprocessable();

        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $draft = $this->postJson('/api/v1/shipments', [
            'customer_id' => $customer->id, 'consignee_name' => 'X', 'consignee_phone' => '0912345678',
            'destination_id' => DB::table('destinations')->value('id'), 'currency_id' => 1, 'price' => '10',
            'items' => [['name' => 'A', 'qty' => 1, 'unit' => 'piece']],
        ])->json('data.id');

        $this->postJson('/api/v1/payments', ['shipment_id' => $draft, 'treasury_id' => $this->egp->id, 'amount' => '5'])
            ->assertUnprocessable()->assertJsonValidationErrors('shipment_id');
    }

    public function test_branch_staff_cannot_use_another_branchs_treasury_or_collect_foreign_shipments(): void
    {
        $other = Treasury::create(['branch_id' => Branch::factory()->create()->id, 'currency_id' => 1, 'method' => 'cash']);

        $this->collect('10', [], $other)->assertUnprocessable()->assertJsonValidationErrors('treasury_id');

        $this->signInAs('branch_staff', ['branch_id' => $other->branch_id]);
        $this->collect('10', [], $other)->assertNotFound();
    }

    public function test_payment_in_another_currency_is_recorded_without_reducing_the_shipment_currency_balance(): void
    {
        $usd = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 2, 'method' => 'cash']);

        $this->collect('50', [], $usd)->assertCreated();

        $this->getJson("/api/v1/shipments/{$this->shipmentId}")
            ->assertJsonPath('data.balance.remaining', '1000.00')
            ->assertJsonPath('data.balance.other_currencies.0.currency_id', 2)
            ->assertJsonPath('data.balance.other_currencies.0.net', '50.00');
    }

    public function test_reversal_corrects_a_payment_once_and_keeps_both_rows(): void
    {
        $paymentId = $this->collect('400')->json('data.id');

        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'typo'])->assertForbidden();

        $this->signInAs('accountant');
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'typo'])
            ->assertCreated()->assertJsonPath('data.direction', 'out')->assertJsonPath('data.reversal_of_id', $paymentId);
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'again'])->assertUnprocessable();

        $this->assertSame(2, DB::table('payments')->where('shipment_id', $this->shipmentId)->count());
        $this->getJson("/api/v1/shipments/{$this->shipmentId}")->assertJsonPath('data.balance.remaining', '1000.00');
        $this->getJson("/api/v1/treasuries/{$this->egp->id}")->assertJsonPath('data.balance', '0.00');
    }

    public function test_cancelling_refunds_everything_that_was_collected(): void
    {
        $this->collect('250')->assertCreated();
        $this->collect('100')->assertCreated();

        $this->postJson("/api/v1/shipments/{$this->shipmentId}/cancel", ['reason' => 'customer changed mind'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.balance.paid', '0.00');

        $this->assertSame(2, DB::table('payments')->where(['shipment_id' => $this->shipmentId, 'kind' => 'refund'])->count());
        $this->getJson("/api/v1/treasuries/{$this->egp->id}")->assertJsonPath('data.balance', '0.00');
        $this->collect('10')->assertUnprocessable();
        $this->postJson("/api/v1/shipments/{$this->shipmentId}/cancel", ['reason' => 'again'])->assertUnprocessable();
    }

    public function test_loaded_shipments_cannot_be_cancelled(): void
    {
        DB::table('shipments')->where('id', $this->shipmentId)->update(['status' => 'loaded']);

        $this->postJson("/api/v1/shipments/{$this->shipmentId}/cancel", ['reason' => 'late'])->assertUnprocessable();
    }

    public function test_installments_must_fit_the_unscheduled_balance_and_are_paid_by_covering_payments(): void
    {
        $this->collect('200')->assertCreated();
        $tomorrow = now()->addDay()->toDateString();

        $this->postJson("/api/v1/shipments/{$this->shipmentId}/installments", [
            'installments' => [['due_date' => $tomorrow, 'amount' => '500'], ['due_date' => now()->addMonth()->toDateString(), 'amount' => '400']],
        ])->assertUnprocessable()->assertJsonValidationErrors('installments');

        $response = $this->postJson("/api/v1/shipments/{$this->shipmentId}/installments", [
            'installments' => [['due_date' => $tomorrow, 'amount' => '500'], ['due_date' => now()->addMonth()->toDateString(), 'amount' => '300']],
        ])->assertOk();
        $this->assertCount(2, $response->json('data.installments'));

        $this->postJson("/api/v1/shipments/{$this->shipmentId}/installments", [
            'installments' => [['due_date' => $tomorrow, 'amount' => '1']],
        ])->assertUnprocessable();

        $first = $response->json('data.installments.0.id');
        $this->collect('499', ['installment_id' => $first])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $paid = $this->collect('500', ['installment_id' => $first])->assertCreated();

        $this->assertSame('paid', DB::table('installments')->where('id', $first)->value('status'));
        $this->collect('1', ['installment_id' => $first])->assertUnprocessable()->assertJsonValidationErrors('installment_id');

        $this->signInAs('accountant');
        $this->postJson('/api/v1/payments/'.$paid->json('data.id').'/reverse', ['reason' => 'bounced'])->assertCreated();
        $this->assertSame('due', DB::table('installments')->where('id', $first)->value('status'));
    }

    public function test_overdue_command_flags_late_installments(): void
    {
        $this->collect('100')->assertCreated();
        DB::table('installments')->insert([
            ['shipment_id' => $this->shipmentId, 'due_date' => now()->subDays(3)->toDateString(), 'amount' => 100, 'currency_id' => 1, 'status' => 'due'],
            ['shipment_id' => $this->shipmentId, 'due_date' => now()->addDays(3)->toDateString(), 'amount' => 100, 'currency_id' => 1, 'status' => 'due'],
        ]);

        $this->artisan('gomana:mark-overdue-installments')->assertSuccessful();

        $this->assertSame(1, DB::table('installments')->where('status', 'overdue')->count());
        $this->assertSame(1, DB::table('installments')->where('status', 'due')->count());
    }

    public function test_treasury_management_and_visibility(): void
    {
        $this->signInAs('accountant');
        $this->postJson('/api/v1/treasuries', ['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/treasuries', ['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'instapay'])
            ->assertCreated();
        $this->getJson('/api/v1/treasuries')->assertOk()->assertJsonCount(2, 'data');

        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $this->postJson('/api/v1/treasuries', ['branch_id' => $this->branch->id, 'currency_id' => 2, 'method' => 'cash'])->assertForbidden();
        $this->getJson('/api/v1/treasuries')->assertOk()->assertJsonCount(2, 'data');

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->getJson('/api/v1/treasuries')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(0, 'data');
    }
}
