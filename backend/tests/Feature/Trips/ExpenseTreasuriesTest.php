<?php

namespace Tests\Feature\Trips;

use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseTreasuriesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Treasury $mainCash;

    private Treasury $branchCash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainCash = Treasury::create(['branch_id' => Branch::where('is_main_warehouse', true)->value('id'), 'currency_id' => 1, 'method' => 'cash']);
        $this->branchCash = Treasury::create(['branch_id' => Branch::factory()->create()->id, 'currency_id' => 1, 'method' => 'cash']);
        app(LedgerService::class)->post($this->mainCash, 'in', 'manual', '800.00');
    }

    public function test_the_warehouse_employee_can_pick_the_warehouse_treasury_with_its_balance(): void
    {
        $this->signInAs('faisal_staff');

        $response = $this->getJson('/api/v1/expenses/treasuries')->assertOk();

        $this->assertSame([$this->mainCash->id], collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonPath('data.0.balance', '800.00')->assertJsonPath('data.0.currency.code', 'EGP');
    }

    public function test_users_who_can_see_other_treasuries_get_those_too(): void
    {
        $this->signInAs('accountant');

        $ids = collect($this->getJson('/api/v1/expenses/treasuries')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->mainCash->id, $this->branchCash->id], $ids);
    }

    public function test_stopped_treasuries_and_other_roles_are_excluded(): void
    {
        $this->mainCash->update(['active' => false]);
        $this->signInAs('faisal_staff');
        $this->getJson('/api/v1/expenses/treasuries')->assertOk()->assertJsonCount(0, 'data');

        $this->signInAs('branch_staff', ['branch_id' => $this->branchCash->branch_id]);
        $this->getJson('/api/v1/expenses/treasuries')->assertForbidden();
    }
}
