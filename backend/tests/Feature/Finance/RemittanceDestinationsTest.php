<?php

namespace Tests\Feature\Finance;

use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemittanceDestinationsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    public function test_branch_staff_see_only_the_main_warehouse_treasuries_as_destinations(): void
    {
        $branch = Branch::factory()->create();
        $main = Branch::where('is_main_warehouse', true)->firstOrFail();
        Treasury::create(['branch_id' => $branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $mainCash = Treasury::create(['branch_id' => $main->id, 'currency_id' => 1, 'method' => 'cash']);
        Treasury::create(['branch_id' => $main->id, 'currency_id' => 2, 'method' => 'instapay', 'active' => false]);
        $this->signInAs('branch_staff', ['branch_id' => $branch->id]);

        $response = $this->getJson('/api/v1/remittances/destinations')->assertOk();

        $this->assertSame([$mainCash->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('data.0.currency.code', 'EGP')->assertJsonPath('data.0.branch.name', $main->name);
    }

    public function test_only_senders_can_list_destinations(): void
    {
        $this->signInAs('faisal_staff');

        $this->getJson('/api/v1/remittances/destinations')->assertForbidden();
    }
}
