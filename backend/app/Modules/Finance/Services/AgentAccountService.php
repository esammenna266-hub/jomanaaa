<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Models\Agent;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an external office owes the company, per currency. Nothing is stored: the amount due comes from the
 * office's bookings and shipments (at the office's own price), the amount paid from the ledger.
 */
final class AgentAccountService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * @return list<array{currency_id: int, due: string, paid: string, remaining: string}>
     */
    public function balances(int $agentId): array
    {
        $due = [];

        $bookings = DB::table('bookings')
            ->where('agent_id', $agentId)
            ->whereIn('status', ['reserved', 'paid', 'travelled'])
            ->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(agent_price) AS total')
            ->pluck('total', 'currency_id');

        // Same statuses as v_shipment_balances: drafts and cancelled shipments cost the office nothing.
        $shipments = DB::table('shipments')
            ->where('agent_id', $agentId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->groupBy('currency_id')
            ->selectRaw('currency_id, SUM(price - discount) AS total')
            ->pluck('total', 'currency_id');

        foreach ([$bookings, $shipments] as $totals) {
            foreach ($totals as $currencyId => $total) {
                $due[$currencyId] = ($due[$currencyId] ?? BigDecimal::zero())->plus($total);
            }
        }

        $paid = $this->paid($agentId);

        $currencies = array_values(array_unique([...array_keys($due), ...array_keys($paid)]));
        sort($currencies);

        return array_map(function (int $currencyId) use ($due, $paid): array {
            $owed = ($due[$currencyId] ?? BigDecimal::zero())->toScale(2);
            $settled = ($paid[$currencyId] ?? BigDecimal::zero())->toScale(2);

            return [
                'currency_id' => $currencyId,
                'due' => (string) $owed,
                'paid' => (string) $settled,
                'remaining' => (string) $owed->minus($settled),
            ];
        }, $currencies);
    }

    /**
     * Records money the office paid to the company. It cannot exceed what the office still owes in that currency.
     */
    public function settle(Agent $agent, Treasury $treasury, string $amount, ?string $notes): Payment
    {
        if (! $treasury->active) {
            throw ValidationException::withMessages(['treasury_id' => 'الخزنة موقوفة']);
        }

        return DB::transaction(function () use ($agent, $treasury, $amount, $notes): Payment {
            // Serialise settlements of one office so two clerks cannot both pay off the same balance.
            Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();

            $balance = collect($this->balances($agent->id))->firstWhere('currency_id', $treasury->currency_id);

            if (BigDecimal::of($amount)->isGreaterThan($balance['remaining'] ?? '0')) {
                throw ValidationException::withMessages(['amount' => 'المبلغ أكبر من المتبقي على المكتب في العملة دي']);
            }

            return $this->ledger->post($treasury, 'in', 'agent_settlement', $amount, [
                'payer_type' => 'agent',
                'payer_id' => $agent->id,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Money the office paid, newest first: the settlements and their reversals.
     *
     * @return Collection<int, Payment>
     */
    public function settlements(int $agentId): Collection
    {
        return Payment::where('payer_type', 'agent')->where('payer_id', $agentId)->orderByDesc('id')->limit(100)->get();
    }

    /**
     * @return array<int, BigDecimal> currency_id => net paid
     */
    private function paid(int $agentId): array
    {
        return DB::table('payments')
            ->where('payer_type', 'agent')
            ->where('payer_id', $agentId)
            ->groupBy('currency_id')
            ->selectRaw("currency_id, SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END) AS net")
            ->pluck('net', 'currency_id')
            ->map(fn ($net): BigDecimal => BigDecimal::of($net))
            ->all();
    }
}
