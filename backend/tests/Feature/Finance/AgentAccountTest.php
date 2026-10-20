<?php

namespace Tests\Feature\Finance;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PassengerTrip;
use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\AgentPrice;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * External offices: their price list (AG-03) and their account with the company (due, paid, remaining).
 */
class AgentAccountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Agent $office;

    private Branch $branch;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Agent::factory()->create();
        $this->branch = Branch::factory()->create();
        $this->treasury = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
    }

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create([
            'agent_id' => $this->office->id,
            'agent_price' => '2000.00',
            'status' => 'reserved',
            ...$overrides,
        ]);
    }

    public function test_the_owner_manages_a_price_list_with_one_price_per_service_route_and_currency(): void
    {
        $this->signInAs('owner');
        $url = "/api/v1/agents/{$this->office->id}/prices";
        $price = ['service' => 'bus', 'route' => 'القاهرة - الخرطوم', 'price' => '2000', 'currency_id' => 1];

        $id = $this->postJson($url, $price)->assertCreated()->assertJsonPath('data.price', '2000.00')->json('data.id');
        $this->postJson($url, $price)->assertUnprocessable()->assertJsonValidationErrors('route');
        $this->postJson($url, [...$price, 'currency_id' => 2])->assertCreated();
        $this->postJson($url, [...$price, 'service' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('service');

        $this->putJson("/api/v1/agent-prices/{$id}", ['price' => '2100'])->assertOk()->assertJsonPath('data.price', '2100.00');
        $this->putJson("/api/v1/agent-prices/{$id}", ['currency_id' => 2])->assertUnprocessable()->assertJsonValidationErrors('route');

        $this->deleteJson("/api/v1/agent-prices/{$id}")->assertOk();
        $this->assertFalse((bool) AgentPrice::find($id)->active);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_price_managers_see_or_change_price_lists(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $this->getJson("/api/v1/agents/{$this->office->id}/prices")->assertForbidden();
        $this->postJson("/api/v1/agents/{$this->office->id}/prices", ['service' => 'bus', 'route' => 'x', 'price' => '1', 'currency_id' => 1])->assertForbidden();

        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $this->office->id]), 'sanctum');
        $this->getJson("/api/v1/agents/{$this->office->id}/prices")->assertForbidden();
    }

    public function test_a_booking_by_an_office_uses_its_listed_price_and_a_default_otherwise(): void
    {
        $trip = PassengerTrip::factory()->create(['price' => '2500.00']);
        AgentPrice::create(['agent_id' => $this->office->id, 'service' => 'bus', 'route' => $trip->route, 'price' => '1800.00', 'currency_id' => 1]);
        $other = Agent::factory()->create();
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $this->office->id]), 'sanctum');

        $id = $this->postJson('/api/v1/bookings', ['trip_id' => $trip->id, 'seat_no' => 1, 'passenger_name' => 'منى'])->assertCreated()
            ->assertJsonPath('data.sale_price', '2500.00')
            ->assertJsonMissingPath('data.agent_price')
            ->json('data.id');
        $this->assertEquals(1800, Booking::find($id)->makeVisible('agent_price')->agent_price);

        // Another office has no listed price: it pays the trip's price.
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $other->id]), 'sanctum');
        $id = $this->postJson('/api/v1/bookings', ['trip_id' => $trip->id, 'seat_no' => 2, 'passenger_name' => 'سمير'])->assertCreated()->json('data.id');
        $this->assertEquals(2500, Booking::find($id)->makeVisible('agent_price')->agent_price);
    }

    public function test_a_switched_off_price_is_ignored(): void
    {
        $trip = PassengerTrip::factory()->create(['price' => '2500.00']);
        AgentPrice::create(['agent_id' => $this->office->id, 'service' => 'bus', 'route' => $trip->route, 'price' => '1800.00', 'currency_id' => 1, 'active' => false]);
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $this->office->id]), 'sanctum');

        $id = $this->postJson('/api/v1/bookings', ['trip_id' => $trip->id, 'seat_no' => 1, 'passenger_name' => 'منى'])->assertCreated()->json('data.id');

        $this->assertEquals(2500, Booking::find($id)->makeVisible('agent_price')->agent_price);
    }

    public function test_the_account_adds_up_bookings_and_shipments_and_ignores_cancelled_ones(): void
    {
        $this->booking(['seat_no' => 1]);
        $this->booking(['seat_no' => 2, 'agent_price' => '1000.00', 'status' => 'paid']);
        $this->booking(['seat_no' => 3, 'status' => 'cancelled']);
        $this->booking(['seat_no' => 4, 'currency_id' => 2, 'agent_price' => '50.00']);
        $this->signInAs('owner');

        $account = $this->getJson("/api/v1/agents/{$this->office->id}/account")->assertOk()
            ->assertJsonPath('data.agent.id', $this->office->id);

        $this->assertEquals(
            [['currency_id' => 1, 'due' => '3000.00', 'paid' => '0.00', 'remaining' => '3000.00'], ['currency_id' => 2, 'due' => '50.00', 'paid' => '0.00', 'remaining' => '50.00']],
            $account->json('data.balances'),
        );
    }

    public function test_a_settlement_reduces_what_the_office_owes_and_lands_in_the_treasury(): void
    {
        $this->booking(['seat_no' => 1]);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson("/api/v1/agents/{$this->office->id}/settlements", ['treasury_id' => $this->treasury->id, 'amount' => '1500', 'notes' => 'دفعة أولى'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'agent_settlement')
            ->assertJsonPath('data.direction', 'in')
            ->assertJsonPath('data.payer_type', 'agent');

        $this->signInAs('owner');
        $this->getJson("/api/v1/agents/{$this->office->id}/account")->assertOk()
            ->assertJsonPath('data.balances.0.paid', '1500.00')
            ->assertJsonPath('data.balances.0.remaining', '500.00')
            ->assertJsonCount(1, 'data.settlements');
        $this->assertEquals(1500, DB::table('payments')->where('treasury_id', $this->treasury->id)->sum('amount'));
    }

    public function test_an_office_cannot_pay_more_than_it_owes_or_in_a_currency_it_does_not_owe(): void
    {
        $this->booking(['seat_no' => 1]);
        $usd = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 2, 'method' => 'cash']);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $url = "/api/v1/agents/{$this->office->id}/settlements";

        $this->postJson($url, ['treasury_id' => $this->treasury->id, 'amount' => '2000.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['treasury_id' => $usd->id, 'amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['treasury_id' => $this->treasury->id, 'amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['treasury_id' => $this->treasury->id, 'amount' => '2000'])->assertCreated();

        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_a_reversed_settlement_puts_the_amount_back_on_the_account(): void
    {
        $this->booking(['seat_no' => 1]);
        $this->signInAs('accountant');
        $paymentId = $this->postJson("/api/v1/agents/{$this->office->id}/settlements", ['treasury_id' => $this->treasury->id, 'amount' => '2000'])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'غلط في المبلغ'])->assertCreated();

        // Accountants settle and reverse; the account itself is read by the people who manage office prices.
        $this->signInAs('owner');
        $this->getJson("/api/v1/agents/{$this->office->id}/account")->assertOk()
            ->assertJsonPath('data.balances.0.paid', '0.00')
            ->assertJsonPath('data.balances.0.remaining', '2000.00');
    }

    public function test_an_office_sees_its_own_account_but_not_another_and_cannot_settle(): void
    {
        $this->booking(['seat_no' => 1]);
        $rival = Agent::factory()->create();
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => $this->office->id]), 'sanctum');

        $this->getJson("/api/v1/agents/{$this->office->id}/account")->assertOk()->assertJsonPath('data.balances.0.remaining', '2000.00');
        $this->getJson("/api/v1/agents/{$rival->id}/account")->assertForbidden();
        $this->postJson("/api/v1/agents/{$this->office->id}/settlements", ['treasury_id' => $this->treasury->id, 'amount' => '10'])->assertForbidden();
    }

    public function test_staff_cannot_settle_into_a_treasury_of_another_branch(): void
    {
        $this->booking(['seat_no' => 1]);
        $elsewhere = Treasury::create(['branch_id' => Branch::factory()->create()->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson("/api/v1/agents/{$this->office->id}/settlements", ['treasury_id' => $elsewhere->id, 'amount' => '10'])->assertNotFound();
    }
}
