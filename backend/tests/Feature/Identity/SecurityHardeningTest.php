<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pre-delivery security review: account lockout, stopped accounts, sessions after a password change,
 * the company-only branch list and the request limit.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private function login(string $username, string $password, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password]);
    }

    public function test_five_wrong_passwords_lock_the_account_whatever_address_they_come_from(): void
    {
        User::factory()->create(['username' => 'sara', 'password_hash' => 'secret-pass']);

        foreach (range(1, 5) as $attempt) {
            $this->login('sara', 'wrong', "10.0.0.{$attempt}")->assertUnprocessable();
        }

        // Even the right password is refused from yet another address until the lock runs out.
        $this->login('sara', 'secret-pass', '10.0.1.1')->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_good_login_clears_the_failed_attempts(): void
    {
        User::factory()->create(['username' => 'sara', 'password_hash' => 'secret-pass']);

        foreach (range(1, 4) as $attempt) {
            $this->login('sara', 'wrong', "10.0.0.{$attempt}")->assertUnprocessable();
        }
        $this->login('sara', 'secret-pass', '10.0.2.1')->assertOk();
        foreach (range(1, 4) as $attempt) {
            $this->login('sara', 'wrong', "10.0.3.{$attempt}")->assertUnprocessable();
        }

        $this->login('sara', 'secret-pass', '10.0.4.1')->assertOk();
    }

    public function test_the_lock_is_per_account_not_global(): void
    {
        User::factory()->create(['username' => 'sara', 'password_hash' => 'secret-pass']);
        User::factory()->create(['username' => 'omar', 'password_hash' => 'other-pass']);

        foreach (range(1, 5) as $attempt) {
            $this->login('sara', 'wrong', "10.0.0.{$attempt}")->assertUnprocessable();
        }

        $this->login('omar', 'other-pass', '10.0.5.1')->assertOk();
    }

    public function test_stopping_an_account_ends_its_session_at_once(): void
    {
        $user = User::factory()->withRole('branch_staff')->create(['username' => 'sara', 'password_hash' => 'secret-pass']);
        $token = $this->login('sara', 'secret-pass')->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['active' => false]);

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_stopped_account_is_refused_even_when_the_stop_bypassed_the_model(): void
    {
        $user = User::factory()->withRole('branch_staff')->create(['username' => 'sara', 'password_hash' => 'secret-pass']);
        $token = $this->login('sara', 'secret-pass')->assertOk()->json('token');

        DB::table('users')->where('id', $user->id)->update(['active' => 0]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'الحساب ده موقوف');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_changing_the_password_signs_out_the_other_sessions_but_not_this_one(): void
    {
        User::factory()->withRole('branch_staff')->create(['username' => 'sara', 'password_hash' => 'secret-pass']);
        $phone = $this->login('sara', 'secret-pass', '10.0.0.1')->assertOk()->json('token');
        $computer = $this->login('sara', 'secret-pass', '10.0.0.2')->assertOk()->json('token');

        $this->withToken($computer)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'secret-pass',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($computer)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_the_new_password_must_differ_from_the_current_one(): void
    {
        $this->signInAs('branch_staff', ['password_hash' => 'secret-pass']);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'secret-pass',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_external_offices_cannot_read_the_branch_list_but_company_staff_can(): void
    {
        $this->actingAs(User::factory()->withRole('agent')->create(['agent_id' => Agent::factory()->create()->id]), 'sanctum');
        $this->getJson('/api/v1/branches')->assertForbidden();
        $this->getJson('/api/v1/branches/1')->assertForbidden();

        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);
        $this->getJson('/api/v1/branches')->assertOk();
    }

    public function test_a_runaway_client_is_slowed_down(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        foreach (range(1, 240) as $request) {
            $this->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->getJson('/api/v1/auth/me')->assertStatus(429);
    }
}
