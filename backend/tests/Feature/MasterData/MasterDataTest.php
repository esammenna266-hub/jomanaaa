<?php

namespace Tests\Feature\MasterData;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_only_one_main_warehouse_is_allowed(): void
    {
        $this->signInAs('owner');

        $this->postJson('/api/v1/branches', ['name' => 'Second', 'country' => 'EG', 'is_main_warehouse' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_main_warehouse');

        $this->postJson('/api/v1/branches', ['name' => 'Aswan', 'country' => 'EG'])->assertCreated();
    }

    public function test_agent_user_must_be_linked_to_an_office_and_not_a_branch(): void
    {
        $this->signInAs('owner');
        $roleId = \DB::table('roles')->where('code', 'agent')->value('id');

        $this->postJson('/api/v1/users', [
            'name' => 'Office user', 'username' => 'office1', 'password' => 'long-enough-1', 'role_id' => $roleId,
        ])->assertUnprocessable()->assertJsonValidationErrors('agent_id');

        $this->postJson('/api/v1/users', [
            'name' => 'Office user', 'username' => 'office1', 'password' => 'long-enough-1', 'role_id' => $roleId,
            'agent_id' => Agent::factory()->create()->id, 'branch_id' => Branch::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_branch_staff_must_be_linked_to_a_branch(): void
    {
        $this->signInAs('admin');
        $roleId = \DB::table('roles')->where('code', 'branch_staff')->value('id');

        $this->postJson('/api/v1/users', [
            'name' => 'Clerk', 'username' => 'clerk1', 'password' => 'long-enough-1', 'role_id' => $roleId,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_admin_can_reset_a_password_and_old_tokens_die(): void
    {
        $this->signInAs('admin');
        $target = User::factory()->create(['password_hash' => 'old-password-1']);
        $target->createToken('api');

        $temporary = $this->postJson("/api/v1/users/{$target->id}/reset-password")
            ->assertOk()->json('temporary_password');

        $target->refresh();
        $this->assertTrue(password_verify($temporary, $target->password_hash));
        $this->assertTrue($target->must_change_password);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_bus_vehicles_need_a_seat_count(): void
    {
        $this->signInAs('admin');

        $this->postJson('/api/v1/vehicles', ['plate_no' => 'ABC 123', 'category' => 'bus'])
            ->assertUnprocessable()->assertJsonValidationErrors('seats');

        $this->postJson('/api/v1/vehicles', ['plate_no' => 'ABC 123', 'category' => 'bus', 'seats' => 52])
            ->assertCreated();

        $this->postJson('/api/v1/vehicles', ['plate_no' => 'ABC 123', 'category' => 'jumbo', 'body' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors('plate_no');
    }

    public function test_lookup_lists_work_and_delete_deactivates(): void
    {
        $this->signInAs('admin');

        $id = $this->postJson('/api/v1/border-points', ['name' => 'Qastal'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/border-points', ['name' => 'Qastal'])->assertUnprocessable();
        $this->postJson('/api/v1/destinations', ['name' => 'Atbara', 'country' => 'SD'])->assertCreated();
        $this->postJson('/api/v1/clearing-agents', ['name' => 'Al Amin', 'phone' => '0912345678'])
            ->assertCreated()->assertJsonPath('data.phone', '+20912345678');

        $this->deleteJson("/api/v1/border-points/{$id}")->assertOk();
        $this->assertDatabaseHas('border_points', ['id' => $id, 'active' => 0]);
        $this->getJson('/api/v1/border-points?active=1&q=Qastal')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_driver_expiry_cannot_precede_issue_date(): void
    {
        $this->signInAs('admin');

        $this->postJson('/api/v1/drivers', [
            'name' => 'Hassan', 'id_issue_date' => '2025-05-01', 'id_expiry_date' => '2024-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('id_expiry_date');
    }

    public function test_settings_can_be_read_and_updated(): void
    {
        $this->signInAs('owner');

        $this->putJson('/api/v1/settings/late_alert_days_1', ['value' => '7'])->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'late_alert_days_1', 'value' => '7']);
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonFragment(['key' => 'late_alert_days_2']);
    }
}
