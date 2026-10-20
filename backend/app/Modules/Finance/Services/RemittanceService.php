<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Remittance;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Support\SerialNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash handover between treasuries (AC-06): the sender's balance drops when it is sent,
 * the receiver's grows only after it is confirmed, and a rejection puts the money back.
 */
final class RemittanceService
{
    public function __construct(private SerialNumberService $serials, private LedgerService $ledger) {}

    public function send(User $user, Treasury $from, Treasury $to, string $amount): Remittance
    {
        return DB::transaction(function () use ($user, $from, $to, $amount): Remittance {
            // Serialise concurrent sends from the same treasury so the balance check is reliable.
            $source = Treasury::whereKey($from->id)->lockForUpdate()->firstOrFail();

            if ($source->id === $to->id) {
                throw ValidationException::withMessages(['to_treasury_id' => 'مينفعش تورد لنفس الخزنة']);
            }

            if ($source->currency_id !== $to->currency_id) {
                throw ValidationException::withMessages(['to_treasury_id' => 'الخزنتين لازم يكونوا بنفس العملة']);
            }

            if (! $source->active || ! $to->active) {
                throw ValidationException::withMessages(['to_treasury_id' => 'الخزنة موقوفة']);
            }

            if (BigDecimal::of($amount)->isGreaterThan($this->balance($source))) {
                throw ValidationException::withMessages(['amount' => 'المبلغ أكبر من رصيد الخزنة']);
            }

            $remittance = Remittance::create([
                'serial_no' => $this->serials->next('remittance'),
                'from_treasury_id' => $source->id,
                'to_treasury_id' => $to->id,
                'amount' => $amount,
                'currency_id' => $source->currency_id,
                'status' => 'sent',
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);

            $this->ledger->post($source, 'out', 'remittance', $amount, [
                'payer_type' => 'staff',
                'payer_id' => $user->id,
                'remittance_id' => $remittance->id,
                'notes' => "توريد {$remittance->serial_no}",
            ]);

            return $remittance;
        });
    }

    public function confirm(User $user, Remittance $remittance): Remittance
    {
        return DB::transaction(function () use ($user, $remittance): Remittance {
            $locked = $this->lockOpen($remittance);

            $this->ledger->post(Treasury::findOrFail($locked->to_treasury_id), 'in', 'remittance', (string) $locked->amount, [
                'payer_type' => 'staff',
                'payer_id' => $locked->sent_by,
                'remittance_id' => $locked->id,
                'notes' => "استلام توريد {$locked->serial_no}",
            ]);

            $locked->update(['status' => 'confirmed', 'confirmed_by' => $user->id, 'confirmed_at' => now()]);

            return $locked;
        });
    }

    public function reject(User $user, Remittance $remittance, string $reason): Remittance
    {
        return DB::transaction(function () use ($user, $remittance, $reason): Remittance {
            $locked = $this->lockOpen($remittance);

            $outgoing = Payment::where(['remittance_id' => $locked->id, 'direction' => 'out'])->firstOrFail();
            $this->ledger->reverse($outgoing, 'reversal', "رفض توريد {$locked->serial_no}: {$reason}");

            $locked->update(['status' => 'rejected', 'confirmed_by' => $user->id, 'confirmed_at' => now()]);

            return $locked;
        });
    }

    public function balance(Treasury $treasury): BigDecimal
    {
        $net = DB::table('payments')
            ->where('treasury_id', $treasury->id)
            ->selectRaw("COALESCE(SUM(CASE direction WHEN 'in' THEN amount ELSE -amount END), 0) AS net")
            ->value('net');

        return BigDecimal::of($net);
    }

    private function lockOpen(Remittance $remittance): Remittance
    {
        $locked = Remittance::whereKey($remittance->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'sent') {
            throw ValidationException::withMessages(['status' => 'التوريد ده اتقرر فيه قبل كده']);
        }

        return $locked;
    }
}
