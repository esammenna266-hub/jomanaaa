<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Documents\Pdf\PdfRenderer;
use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    /** @var object{calls: list<array{0: string, 1: string}>} */
    private object $renderer;

    private PassengerTrip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->renderer = new class implements PdfRenderer
        {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public function render(string $html, string $paper): string
            {
                $this->calls[] = [$html, $paper];

                return '%PDF-fake';
            }
        };
        $this->app->instance(PdfRenderer::class, $this->renderer);

        $this->trip = PassengerTrip::factory()->create([
            'vehicle_id' => Vehicle::create(['plate_no' => 'BUS 77', 'category' => 'bus', 'seats' => 52])->id,
            'driver_id' => Driver::create(['name' => 'السائق حسن'])->id,
        ]);
    }

    private function issue(string $type, int $subjectId)
    {
        return $this->postJson('/api/v1/documents', ['type' => $type, 'subject_id' => $subjectId]);
    }

    private function html(): string
    {
        return end($this->renderer->calls)[0];
    }

    public function test_the_ticket_is_a_narrow_strip_with_the_passenger_seat_and_price(): void
    {
        $branch = Branch::factory()->create();
        $booking = Booking::factory()->create([
            'trip_id' => $this->trip->id, 'seat_no' => 12, 'passenger_name' => 'سعد محمود', 'passport_no' => 'A1234567',
            'branch_id' => $branch->id, 'sale_price' => '2500.00',
        ]);
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);

        $this->issue('bus_ticket', $booking->id)->assertCreated()->assertJsonPath('data.serial_no', $booking->serial_no);

        $this->assertSame('TICKET', $this->renderer->calls[0][1]);
        foreach (['تذكرة أتوبيس', 'سعد محمود', 'A1234567', 'القاهرة - الخرطوم', 'BUS 77', '2,500.00', 'كعب التذكرة'] as $expected) {
            $this->assertStringContainsString($expected, $this->html());
        }
        $this->assertMatchesRegularExpression('/كرسي\s*<span class="num">12<\/span>/u', $this->html());
    }

    public function test_an_offices_ticket_shows_the_sale_price_and_never_the_cost(): void
    {
        $office = Agent::factory()->create();
        $booking = Booking::factory()->create([
            'trip_id' => $this->trip->id, 'seat_no' => 3, 'agent_id' => $office->id, 'status' => 'reserved',
            'sale_price' => '3100.00', 'agent_price' => '2500.00',
        ]);
        $this->signInAs('agent', ['agent_id' => $office->id]);

        $this->issue('bus_ticket', $booking->id)->assertCreated();

        $this->assertStringContainsString('3,100.00', $this->html());
        $this->assertStringNotContainsString('2,500.00', $this->html());
    }

    public function test_cancelled_bookings_have_no_ticket_and_other_branches_cannot_print_it(): void
    {
        $branch = Branch::factory()->create();
        $cancelled = Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 1, 'status' => 'cancelled', 'branch_id' => $branch->id]);
        $live = Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 2, 'branch_id' => $branch->id]);

        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $this->issue('bus_ticket', $cancelled->id)->assertUnprocessable();

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->issue('bus_ticket', $live->id)->assertNotFound();
    }

    public function test_the_passenger_manifest_is_only_issued_after_departure_and_lists_seats_in_order(): void
    {
        Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 9, 'passenger_name' => 'الراكب التاسع']);
        Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 2, 'passenger_name' => 'الراكب الثاني']);
        Booking::factory()->create(['trip_id' => $this->trip->id, 'seat_no' => 5, 'passenger_name' => 'الراكب الملغي', 'status' => 'cancelled']);
        $this->signInAs('faisal_staff');

        $this->issue('passenger_manifest', $this->trip->id)->assertUnprocessable()->assertJsonValidationErrors('subject_id');

        $this->postJson("/api/v1/trips/{$this->trip->id}/depart")->assertOk();
        $this->issue('passenger_manifest', $this->trip->id)->assertCreated()->assertJsonPath('data.serial_no', '000001');

        $this->assertSame('A4', $this->renderer->calls[0][1]);
        $this->assertStringContainsString('مانيفست الركاب', $this->html());
        $this->assertStringContainsString('السائق حسن', $this->html());
        $this->assertLessThan(strpos($this->html(), 'الراكب التاسع'), strpos($this->html(), 'الراكب الثاني'));
        $this->assertStringNotContainsString('الراكب الملغي', $this->html());
    }

    public function test_only_trip_managers_can_issue_the_manifest(): void
    {
        $this->trip->update(['status' => 'departed']);
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->issue('passenger_manifest', $this->trip->id)->assertForbidden();
    }
}
