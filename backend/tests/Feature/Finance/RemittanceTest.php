<?php

namespace Tests\Feature\Finance;

use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Finance\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemittanceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $dropViews = true;

    private Branch $branch;

    private Treasury $branchCash;

    private Treasury $mainCash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->branchCash = Treasury::create(['branch_id' => $this->branch->id, 'currency_id' => 1, 'method' => 'cash']);
        $this->mainCash = Treasury::create([
            'branch_id' => Branch::where('is_main_warehouse', true)->value('id'), 'currency_id' => 1, 'method' => 'cash',
        ]);

        // Opening cash in the branch drawer: 1000 EGP.
        app(LedgerService::class)->post($this->branchCash, 'in', 'manual', '1000.00');
    }

    private function balance(Treasury $treasury): string
    {
        return (string) DB::table('v_treasury_balances')->where('treasury_id', $treasury->id)->value('balance');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $amount, ?Treasury $to = null): array
    {
        return ['from_treasury_id' => $this->branchCash->id, 'to_treasury_id' => ($to ?? $this->mainCash)->id, 'amount' => $amount];
    }

    public function test_sending_debits_the_branch_now_and_credits_the_receiver_only_on_confirmation(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $id = $this->postJson('/api/v1/remittances', $this->payload('400'))->assertCreated()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.serial_no', '000001')->json('data.id');

        $this->assertEquals(600, $this->balance($this->branchCash));
        $this->assertEquals(0, $this->balance($this->mainCash));

        $this->postJson("/api/v1/remittances/{$id}/confirm")->assertForbidden();

        $this->signInAs('accountant');
        $this->postJson("/api/v1/remittances/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertEquals(600, $this->balance($this->branchCash));
        $this->assertEquals(400, $this->balance($this->mainCash));
        $this->postJson("/api/v1/remittances/{$id}/confirm")->assertUnprocessable();
        $this->postJson("/api/v1/remittances/{$id}/reject", ['reason' => 'late'])->assertUnprocessable();
    }

    public function test_rejection_returns_the_money_to_the_sender(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $id = $this->postJson('/api/v1/remittances', $this->payload('250.50'))->json('data.id');

        $this->signInAs('accountant');
        $this->postJson("/api/v1/remittances/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/remittances/{$id}/reject", ['reason' => 'amount mismatch'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertEquals(1000, $this->balance($this->branchCash));
        $this->assertEquals(0, $this->balance($this->mainCash));
        $this->assertSame(2, DB::table('payments')->where('treasury_id', $this->branchCash->id)->where('kind', '<>', 'manual')->count());
    }

    public function test_cannot_send_more_than_the_balance_or_across_currencies_or_to_the_same_treasury(): void
    {
        $usd = Treasury::create(['branch_id' => $this->mainCash->branch_id, 'currency_id' => 2, 'method' => 'cash']);
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);

        $this->postJson('/api/v1/remittances', $this->payload('1000.01'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/remittances', $this->payload('10', $usd))->assertUnprocessable()->assertJsonValidationErrors('to_treasury_id');
        $this->postJson('/api/v1/remittances', $this->payload('10', $this->branchCash))->assertUnprocessable();
        $this->postJson('/api/v1/remittances', $this->payload('1000'))->assertCreated();
        $this->postJson('/api/v1/remittances', $this->payload('1'))->assertUnprocessable();
    }

    public function test_branch_staff_can_only_send_from_their_own_treasury(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => Branch::factory()->create()->id]);

        $this->postJson('/api/v1/remittances', $this->payload('10'))->assertNotFound();
        $this->getJson('/api/v1/remittances')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_listing_is_scoped_and_remittance_movements_cannot_be_reversed_by_hand(): void
    {
        $this->signInAs('branch_staff', ['branch_id' => $this->branch->id]);
        $this->postJson('/api/v1/remittances', $this->payload('100'))->assertCreated();
        $this->getJson('/api/v1/remittances?status=sent')->assertOk()->assertJsonCount(1, 'data');

        $this->signInAs('accountant');
        $this->getJson('/api/v1/remittances')->assertOk()->assertJsonCount(1, 'data');

        $paymentId = DB::table('payments')->where('kind', 'remittance')->value('id');
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'oops'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
    }
}
