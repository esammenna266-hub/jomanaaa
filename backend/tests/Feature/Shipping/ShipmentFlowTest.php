<?php

namespace Tests\Feature\Shipping;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Setting;
use App\Modules\MasterData\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    /**
     * @return array<string, mixed>
     */
    private function payload(int $customerId, array $overrides = []): array
    {
        return [
            'customer_id' => $customerId,
            'consignee_name' => 'Mohamed',
            'consignee_phone' => '0912345678',
            'destination_id' => DB::table('destinations')->value('id'),
            'currency_id' => 1,
            'price' => '1000.00',
            'items' => [['name' => 'Clothes', 'qty' => 10, 'unit' => 'carton', 'weight_kg' => 120]],
            ...$overrides,
        ];
    }

    public function test_branch_staff_creates_a_draft_with_serial_tracking_code_and_normalized_consignee(): void
    {
        $branch = Branch::factory()->create();
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $branch->id]);

        $response = $this->postJson('/api/v1/shipments', $this->payload($customer->id))->assertCreated();
        $this->assertSame('000001', $response->json('data.serial_no'));
        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame($branch->id, $response->json('data.branch_id'));
        $this->assertSame('+249912345678', $response->json('data.consignee_phone'));
        $this->assertSame(10, strlen($response->json('data.tracking_code')));
        $this->assertSame('1000.00', $response->json('data.balance.remaining'));
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_full_approval_flow_draft_to_received(): void
    {
        $branch = Branch::factory()->create();
        $staff = $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $branch->id]);
        $id = $this->postJson('/api/v1/shipments', $this->payload($customer->id))->json('data.id');

        $this->postJson("/api/v1/shipments/{$id}/receive")->assertUnprocessable();
        $this->postJson("/api/v1/shipments/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->putJson("/api/v1/shipments/{$id}", ['price' => '900'])->assertUnprocessable();

        $approvalId = DB::table('approvals')->where(['subject_id' => $id, 'type' => 'price'])->value('id');
        $this->postJson("/api/v1/approvals/{$approvalId}/decide", ['decision' => 'approve'])->assertForbidden();

        $this->signInAs('owner');
        $this->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shipment.serial_no', '000001');
        $this->postJson("/api/v1/approvals/{$approvalId}/decide", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/v1/approvals/{$approvalId}/decide", ['decision' => 'approve'])->assertUnprocessable();
        $this->assertSame('approved', DB::table('shipments')->where('id', $id)->value('status'));

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->postJson("/api/v1/shipments/{$id}/receive")->assertNotFound();

        $this->actingAs($staff, 'sanctum');
        $this->postJson("/api/v1/shipments/{$id}/receive")->assertOk()->assertJsonPath('data.status', 'at_branch');
    }

    public function test_submit_requires_items_and_a_price(): void
    {
        $branch = Branch::factory()->create();
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $branch->id]);
        $id = $this->postJson('/api/v1/shipments', $this->payload($customer->id, ['price' => null]))->json('data.id');

        $this->postJson("/api/v1/shipments/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_discount_above_the_limit_opens_a_second_approval_and_rejection_returns_to_draft(): void
    {
        Setting::find('discount_limit_amount')->update(['value' => '50']);
        $branch = Branch::factory()->create();
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $branch->id]);

        $small = $this->postJson('/api/v1/shipments', $this->payload($customer->id, ['discount' => '40']))->json('data.id');
        $this->postJson("/api/v1/shipments/{$small}/submit")->assertOk();
        $this->assertSame(1, DB::table('approvals')->where('subject_id', $small)->count());

        $big = $this->postJson('/api/v1/shipments', $this->payload($customer->id, ['discount' => '200']))->json('data.id');
        $this->postJson("/api/v1/shipments/{$big}/submit")->assertOk();
        $this->assertSame(2, DB::table('approvals')->where('subject_id', $big)->count());

        $this->signInAs('owner');
        $discountApproval = DB::table('approvals')->where(['subject_id' => $big, 'type' => 'discount'])->value('id');
        $priceApproval = DB::table('approvals')->where(['subject_id' => $big, 'type' => 'price'])->value('id');

        $this->postJson("/api/v1/approvals/{$priceApproval}/decide", ['decision' => 'approve'])->assertOk();
        $this->assertSame('pending_approval', DB::table('shipments')->where('id', $big)->value('status'));

        $this->postJson("/api/v1/approvals/{$discountApproval}/decide", ['decision' => 'reject'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/approvals/{$discountApproval}/decide", ['decision' => 'reject', 'reason' => 'too high'])->assertOk();
        $this->assertSame('draft', DB::table('shipments')->where('id', $big)->value('status'));
    }

    public function test_discount_cannot_exceed_price(): void
    {
        $branch = Branch::factory()->create();
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $branch->id]);

        $this->postJson('/api/v1/shipments', $this->payload($customer->id, ['discount' => '5000']))
            ->assertUnprocessable()->assertJsonValidationErrors('discount');
    }

    public function test_staff_cannot_use_a_customer_outside_their_scope_or_see_foreign_shipments(): void
    {
        [$mine, $other] = Branch::factory()->count(2)->create();
        $foreignCustomer = Customer::factory()->create(['branch_id' => $other->id]);
        $this->signInAs('branch_staff', ['branch_id' => $mine->id]);

        $this->postJson('/api/v1/shipments', $this->payload($foreignCustomer->id))
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->signInAs('owner');
        $owner = $this->postJson('/api/v1/shipments', $this->payload($foreignCustomer->id, ['branch_id' => $other->id]))
            ->assertCreated()->json('data.id');

        $this->signInAs('branch_staff', ['branch_id' => $mine->id]);
        $this->getJson("/api/v1/shipments/{$owner}")->assertNotFound();
        $this->getJson('/api/v1/shipments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_agent_shipments_land_in_the_main_warehouse_and_stay_private_to_the_office(): void
    {
        [$office, $rival] = Agent::factory()->count(2)->create();
        $customer = Customer::factory()->create(['agent_id' => $office->id]);
        $this->signInAs('agent', ['agent_id' => $office->id]);

        $id = $this->postJson('/api/v1/shipments', $this->payload($customer->id, ['agent_sale_price' => '1500']))
            ->assertCreated()->assertJsonPath('data.agent_id', $office->id)->assertJsonPath('data.agent_sale_price', '1500.00')->json('data.id');
        $this->assertSame(DB::table('branches')->where('is_main_warehouse', 1)->value('id'), DB::table('shipments')->where('id', $id)->value('branch_id'));

        $this->signInAs('agent', ['agent_id' => $rival->id]);
        $this->getJson("/api/v1/shipments/{$id}")->assertNotFound();
    }

    public function test_search_finds_by_tracking_code_and_consignee(): void
    {
        $branch = Branch::factory()->create();
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        $customer = Customer::factory()->create(['branch_id' => $branch->id]);
        $created = $this->postJson('/api/v1/shipments', $this->payload($customer->id))->json('data');

        $this->getJson('/api/v1/shipments?q='.$created['tracking_code'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/shipments?q=Mohamed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/shipments?q=nobody')->assertOk()->assertJsonCount(0, 'data');
    }
}
