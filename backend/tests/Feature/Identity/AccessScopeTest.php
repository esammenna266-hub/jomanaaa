<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Identity\Models\User;
use App\Modules\MasterData\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessScopeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_branch_staff_only_see_customers_of_their_branch(): void
    {
        [$mine, $other] = Branch::factory()->count(2)->create();
        $own = Customer::factory()->create(['branch_id' => $mine->id]);
        $foreign = Customer::factory()->create(['branch_id' => $other->id]);
        $this->signInAs('branch_staff', ['branch_id' => $mine->id]);

        $ids = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($foreign->id));
        $this->getJson("/api/v1/customers/{$foreign->id}")->assertNotFound();
    }

    public function test_agents_only_see_their_own_customers(): void
    {
        [$office, $rival] = Agent::factory()->count(2)->create();
        $own = Customer::factory()->create(['agent_id' => $office->id]);
        $foreign = Customer::factory()->create(['agent_id' => $rival->id]);
        $this->signInAs('agent', ['agent_id' => $office->id]);

        $ids = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$own->id], $ids->all());
        $this->putJson("/api/v1/customers/{$foreign->id}", ['name' => 'Hijack'])->assertNotFound();
    }

    public function test_owner_sees_every_customer(): void
    {
        Customer::factory()->count(3)->create();
        $this->signInAs('owner');

        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_new_customer_is_forced_into_the_creators_scope(): void
    {
        $office = Agent::factory()->create();
        $other = Agent::factory()->create();
        $this->signInAs('agent', ['agent_id' => $office->id]);

        $this->postJson('/api/v1/customers', [
            'name' => 'Omar',
            'phone' => '0912345678',
            'country' => 'SD',
            'agent_id' => $other->id,
        ])->assertCreated()
            ->assertJsonPath('data.agent_id', $office->id)
            ->assertJsonPath('data.phone', '+249912345678');
    }

    public function test_duplicate_phone_in_the_same_scope_is_rejected(): void
    {
        $branch = Branch::factory()->create();
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);
        Customer::factory()->create(['phone' => '+201012345678', 'branch_id' => $branch->id]);

        $this->postJson('/api/v1/customers', ['name' => 'Dup', 'phone' => '01012345678'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_database_rejects_duplicate_company_customers_even_with_null_agent(): void
    {
        Customer::factory()->create(['phone' => '+201012345678', 'agent_id' => null]);

        $this->expectException(QueryException::class);

        Customer::factory()->create(['phone' => '+201012345678', 'agent_id' => null]);
    }

    public function test_roles_without_the_permission_are_forbidden(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->postJson('/api/v1/branches', ['name' => 'X', 'country' => 'EG'])->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_per_user_permission_extends_the_role(): void
    {
        $user = $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        $permissionId = \DB::table('permissions')->where('code', 'audit.view')->value('id');
        \DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $permissionId, 'scope' => 'all']);

        $this->app->forgetInstance('auth');
        $this->signInAsExisting($user->fresh());

        $this->getJson('/api/v1/audit-logs')->assertOk();
    }

    private function signInAsExisting(User $user): void
    {
        Sanctum::actingAs($user);
    }
}
