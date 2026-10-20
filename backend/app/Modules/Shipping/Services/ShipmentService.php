<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Booking\Services\PassengerTripService;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Setting;
use App\Modules\Finance\Models\Installment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Approval;
use App\Modules\Shipping\Models\Shipment;
use App\Support\SerialNumberService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shipment form lifecycle for the branch side: draft -> approval -> received at branch -> cancelled.
 * Later stages (warehouse transfer, loading, tracking) arrive in the next increments.
 */
final class ShipmentService
{
    public function __construct(private SerialNumberService $serials, private LedgerService $ledger, private PassengerTripService $trips) {}

    /**
     * An express shipment may ride a bus trip (`bus_trip_id`) and take one of its express seats.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(User $user, array $data, array $items): Shipment
    {
        return DB::transaction(function () use ($user, $data, $items): Shipment {
            if (($data['bus_trip_id'] ?? null) !== null) {
                $this->requireExpress($data['type'] ?? 'normal');
                $this->trips->claimExpressSeat((int) $data['bus_trip_id']);
            }

            $shipment = Shipment::create([
                ...$data,
                'serial_no' => $this->serials->next('shipment_form'),
                'tracking_code' => $this->uniqueTrackingCode(),
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            $shipment->items()->createMany($items);

            return $shipment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $items
     */
    public function update(Shipment $shipment, array $data, ?array $items): Shipment
    {
        return DB::transaction(function () use ($shipment, $data, $items): Shipment {
            $locked = $this->lock($shipment);
            $this->requireStatus($locked, ['draft'], 'الاستمارة بتتعدل وهي مسودة بس');

            $data = $this->resolveBusTrip($locked, $data);
            $locked->update($data);

            if ($items !== null) {
                $locked->items()->delete();
                $locked->items()->createMany($items);
            }

            return $locked->refresh();
        });
    }

    public function submit(Shipment $shipment, User $user): Shipment
    {
        return DB::transaction(function () use ($shipment, $user): Shipment {
            $locked = $this->lock($shipment);
            $this->requireStatus($locked, ['draft'], 'الاستمارة اتبعتت للاعتماد قبل كده');

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages(['items' => 'ضيف صنف واحد على الأقل']);
            }

            if (BigDecimal::of($locked->price)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['price' => 'حدد السعر قبل الإرسال للاعتماد']);
            }

            $locked->update(['status' => 'pending_approval']);

            $this->openApproval($locked, $user, 'price', $locked->price);

            $limit = BigDecimal::of(Setting::find('discount_limit_amount')?->value ?? '0');
            if (BigDecimal::of($locked->discount)->isGreaterThan($limit)) {
                $this->openApproval($locked, $user, 'discount', $locked->discount);
            }

            return $locked;
        });
    }

    /**
     * Approving the last open request moves the shipment to "approved"; a rejection sends it back to draft.
     */
    public function decide(Approval $approval, User $user, bool $approve, ?string $reason): Approval
    {
        return DB::transaction(function () use ($approval, $user, $approve, $reason): Approval {
            $locked = Approval::whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'الطلب ده اتقرر فيه قبل كده']);
            }

            if (! $approve && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages(['reason' => 'اكتب سبب الرفض']);
            }

            $locked->update([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $user->id,
                'decided_at' => now(),
                'reason' => $reason,
            ]);

            $shipment = Shipment::whereKey($locked->subject_id)->lockForUpdate()->firstOrFail();

            if ($shipment->status !== 'pending_approval') {
                return $locked;
            }

            if (! $approve) {
                Approval::where(['subject_type' => 'shipment', 'subject_id' => $shipment->id, 'status' => 'pending'])
                    ->get()->each->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'reason' => $reason]);
                $shipment->update(['status' => 'draft']);
            } elseif (! Approval::where(['subject_type' => 'shipment', 'subject_id' => $shipment->id, 'status' => 'pending'])->exists()) {
                $shipment->update(['status' => 'approved']);
            }

            return $locked;
        });
    }

    public function receive(Shipment $shipment): Shipment
    {
        return DB::transaction(function () use ($shipment): Shipment {
            $locked = $this->lock($shipment);
            $this->requireStatus($locked, ['approved'], 'الشحنة لازم تكون معتمدة الأول');
            $locked->update(['status' => 'at_branch']);

            return $locked;
        });
    }

    /**
     * Cancels the shipment and refunds everything collected, one reversal per original payment (TR-05).
     */
    public function cancel(Shipment $shipment, string $reason): Shipment
    {
        return DB::transaction(function () use ($shipment, $reason): Shipment {
            $locked = $this->lock($shipment);
            $this->requireStatus($locked, Shipment::CANCELLABLE, 'الشحنة مينفعش تتلغي في المرحلة دي');

            Payment::where('shipment_id', $locked->id)
                ->where('direction', 'in')
                ->whereNull('reversal_of_id')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('payments as r')->whereColumn('r.reversal_of_id', 'payments.id'))
                ->get()
                ->each(fn (Payment $payment) => $this->ledger->reverse($payment, 'refund', $reason));

            Installment::where('shipment_id', $locked->id)->whereIn('status', ['due', 'overdue'])
                ->get()->each->update(['status' => 'cancelled']);

            Approval::where(['subject_type' => 'shipment', 'subject_id' => $locked->id, 'status' => 'pending'])
                ->get()->each->update(['status' => 'rejected', 'reason' => 'الشحنة اتلغت']);

            // The express seat goes back to the trip.
            $locked->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'bus_trip_id' => null]);

            return $locked;
        });
    }

    /**
     * Hands the goods to the consignee. Refused while anything is still owed (SH-08).
     */
    public function deliver(Shipment $shipment, string $receiverName): Shipment
    {
        return DB::transaction(function () use ($shipment, $receiverName): Shipment {
            $locked = $this->lock($shipment);
            $this->requireStatus($locked, ['arrived'], 'الشحنة لازم توصل الأول');

            $remaining = BigDecimal::of($this->balance($locked)['remaining']);
            if ($remaining->isGreaterThan(0)) {
                throw ValidationException::withMessages(['balance' => "عليها متبقي {$remaining} مينفعش تتسلم"]);
            }

            Installment::where('shipment_id', $locked->id)->whereIn('status', ['due', 'overdue'])
                ->get()->each->update(['status' => 'cancelled']);

            $locked->update(['status' => 'delivered', 'delivered_at' => now(), 'delivered_to' => $receiverName]);

            return $locked;
        });
    }

    /**
     * Total due, what was paid in the shipment's currency, and what is left (open point 1 assumption).
     *
     * @return array{currency_id: int, total_due: string, paid: string, remaining: string, other_currencies: list<array{currency_id: int, net: string}>}
     */
    public function balance(Shipment $shipment): array
    {
        $net = $this->ledger->netByCurrency($shipment->id);
        $totalDue = BigDecimal::of($shipment->price)->minus($shipment->discount)->toScale(2);
        $paid = $net[$shipment->currency_id] ?? BigDecimal::zero()->toScale(2);

        $others = [];
        foreach ($net as $currencyId => $amount) {
            if ($currencyId !== $shipment->currency_id && ! $amount->isZero()) {
                $others[] = ['currency_id' => $currencyId, 'net' => (string) $amount];
            }
        }

        return [
            'currency_id' => $shipment->currency_id,
            'total_due' => (string) $totalDue,
            'paid' => (string) $paid,
            'remaining' => (string) $totalDue->minus($paid),
            'other_currencies' => $others,
        ];
    }

    /**
     * Branch where a new shipment belongs: the creator's branch, or Faisal for external offices.
     */
    public function branchFor(User $user, ?int $requested): ?int
    {
        if ($user->agent_id !== null) {
            return Branch::where('is_main_warehouse', true)->value('id');
        }

        return $user->scopeFor('shipment.create') === 'branch' ? $user->branch_id : $requested;
    }

    /**
     * Applies a (possibly absent) bus trip change to the data about to be saved: a new trip needs a free express
     * seat, a shipment turned into a normal one lets its seat go, and only express shipments may name a trip.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBusTrip(Shipment $shipment, array $data): array
    {
        $type = $data['type'] ?? $shipment->type;
        $tripId = array_key_exists('bus_trip_id', $data) ? $data['bus_trip_id'] : $shipment->bus_trip_id;

        if ($type !== 'express') {
            if (array_key_exists('bus_trip_id', $data) && $data['bus_trip_id'] !== null) {
                $this->requireExpress($type);
            }

            return [...$data, 'bus_trip_id' => null];
        }

        if ($tripId !== null && (int) $tripId !== (int) $shipment->bus_trip_id) {
            $this->trips->claimExpressSeat((int) $tripId, $shipment->id);
        }

        return $data;
    }

    private function requireExpress(string $type): void
    {
        if ($type !== 'express') {
            throw ValidationException::withMessages(['bus_trip_id' => 'ربط الشحنة برحلة أتوبيس للشحن السريع بس']);
        }
    }

    private function lock(Shipment $shipment): Shipment
    {
        return Shipment::whereKey($shipment->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function requireStatus(Shipment $shipment, array $allowed, string $message): void
    {
        if (! in_array($shipment->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function openApproval(Shipment $shipment, User $user, string $type, string $value): void
    {
        Approval::create([
            'type' => $type,
            'subject_type' => 'shipment',
            'subject_id' => $shipment->id,
            'requested_by' => $user->id,
            'value' => $value,
        ]);
    }

    private function uniqueTrackingCode(): string
    {
        do {
            $code = strtoupper(Str::random(10));
        } while (Shipment::where('tracking_code', $code)->exists());

        return $code;
    }
}
