<?php

namespace Tests;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function signInAs(string $roleCode, array $attributes = []): User
    {
        $user = User::factory()->withRole($roleCode)->create($attributes);
        Sanctum::actingAs($user);

        return $user;
    }
}
