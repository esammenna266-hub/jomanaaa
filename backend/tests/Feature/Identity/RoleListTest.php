<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleListTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_user_managers_can_list_the_roles(): void
    {
        $this->signInAs('admin');

        $response = $this->getJson('/api/v1/roles')->assertOk();

        $this->assertSame(
            ['owner', 'admin', 'branch_staff', 'faisal_staff', 'accountant', 'agent'],
            collect($response->json('data'))->pluck('code')->all(),
        );
        $response->assertJsonStructure(['data' => [['id', 'code', 'name_ar']]]);
    }

    public function test_other_roles_cannot(): void
    {
        $this->signInAs('branch_staff');

        $this->getJson('/api/v1/roles')->assertForbidden();
    }
}
