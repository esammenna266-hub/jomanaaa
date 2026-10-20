<?php

namespace Tests\Feature\Shipping;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\MasterData\Models\BorderPoint;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Destination;
use App\Modules\Shipping\Models\Approval;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentFileTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_the_shipment_file_carries_names_and_approvals_for_the_screen(): void
    {
        $branch = Branch::factory()->create(['name' => 'فرع القاهرة']);
        $border = BorderPoint::where('name', 'أرقين')->firstOrFail();
        $shipment = Shipment::factory()->create([
            'branch_id' => $branch->id,
            'border_point_id' => $border->id,
            'status' => 'pending_approval',
            'customer_id' => Customer::factory()->create(['branch_id' => $branch->id])->id,
        ]);
        $requester = $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        Approval::create(['type' => 'price', 'subject_type' => 'shipment', 'subject_id' => $shipment->id, 'requested_by' => $requester->id, 'value' => '1000']);

        $this->getJson("/api/v1/shipments/{$shipment->id}")->assertOk()
            ->assertJsonPath('data.branch.name', 'فرع القاهرة')
            ->assertJsonPath('data.border_point.name', 'أرقين')
            ->assertJsonPath('data.destination.name', Destination::first()->name)
            ->assertJsonPath('data.approvals.0.type', 'price')
            ->assertJsonPath('data.approvals.0.status', 'pending');
    }

    public function test_form_options_list_only_active_choices_for_anyone_who_can_create_shipments(): void
    {
        BorderPoint::where('name', 'حلفا')->update(['active' => false]);
        $office = Agent::factory()->create();
        $this->signInAs('agent', ['agent_id' => $office->id]);

        $response = $this->getJson('/api/v1/shipping/options')->assertOk();

        $this->assertSame(['أرقين', 'حدربة'], collect($response->json('data.border_points'))->pluck('name')->sort()->values()->all());
        $this->assertContains('أم درمان', collect($response->json('data.destinations'))->pluck('name')->all());
        $response->assertJsonStructure(['data' => ['border_points', 'destinations', 'clearing_agents']]);
    }

    public function test_form_options_are_closed_to_roles_that_cannot_create_shipments(): void
    {
        $this->signInAs('accountant');

        $this->getJson('/api/v1/shipping/options')->assertForbidden();
    }

    public function test_every_signed_in_user_can_read_the_currencies(): void
    {
        $this->getJson('/api/v1/currencies')->assertUnauthorized();

        $this->signInAs('agent', ['agent_id' => Agent::factory()->create()->id]);

        $response = $this->getJson('/api/v1/currencies')->assertOk();
        $this->assertSame(['EGP', 'USD', 'SDG'], collect($response->json('data'))->pluck('code')->all());
    }
}
