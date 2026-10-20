<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_it_creates_the_first_owner_once(): void
    {
        $this->artisan('gomana:create-owner', ['username' => 'boss', '--name' => 'المالك'])
            ->expectsQuestion('Password (min 8 characters)', 'a-strong-pass')
            ->assertSuccessful();

        $owner = User::where('username', 'boss')->firstOrFail();
        $this->assertSame('owner', $owner->role->code);
        $this->assertTrue(password_verify('a-strong-pass', $owner->password_hash));

        $this->artisan('gomana:create-owner', ['username' => 'second'])->assertFailed();
    }

    public function test_it_rejects_short_passwords(): void
    {
        $this->artisan('gomana:create-owner', ['username' => 'boss'])
            ->expectsQuestion('Password (min 8 characters)', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['username' => 'boss']);
    }
}
