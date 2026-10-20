<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_user_can_log_in_and_receives_token_and_permissions(): void
    {
        User::factory()->withRole('owner')->create(['username' => 'boss', 'password_hash' => 'secret-pass']);

        $response = $this->postJson('/api/v1/auth/login', ['username' => 'boss', 'password' => 'secret-pass']);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'username', 'role', 'permissions']])
            ->assertJsonPath('user.role.code', 'owner')
            ->assertJsonMissingPath('user.password_hash');

        $this->assertSame('all', $response->json('user.permissions')['branch.manage']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'sara', 'password_hash' => 'secret-pass']);

        $this->postJson('/api/v1/auth/login', ['username' => 'sara', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create(['username' => 'old', 'password_hash' => 'secret-pass', 'active' => false]);

        $this->postJson('/api/v1/auth/login', ['username' => 'old', 'password' => 'secret-pass'])
            ->assertUnprocessable();
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_user_can_change_password(): void
    {
        $user = $this->signInAs('branch_staff', ['password_hash' => 'old-password']);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertTrue(password_verify('new-password-1', $user->refresh()->password_hash));
    }
}
