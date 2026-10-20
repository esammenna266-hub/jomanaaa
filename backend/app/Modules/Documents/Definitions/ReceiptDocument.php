<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Treasury;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentService;
use App\Support\AccessScope;
use App\Support\ArabicMoney;
use App\Support\SerialNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * إيصال دفعة: one receipt per collection, with the amount in words and the remaining schedule.
 */
final class ReceiptDocument extends DocumentDefinition
{
    private const METHODS = ['cash' => 'نقدي', 'instapay' => 'إنستاباي', 'vodafone_cash' => 'فودافون كاش'];

    public function __construct(private SerialNumberService $serials, private ShipmentService $shipments) {}

    public function type(): string
    {
        return 'receipt';
    }

    public function paper(): string
    {
        return 'A5';
    }

    public function view(): string
    {
        return 'documents.receipt';
    }

    public function title(): string
    {
        return 'إيصال استلام دفعة';
    }

    public function subjectType(): string
    {
        return 'payment';
    }

    public function find(int $subjectId): Model
    {
        return Payment::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        Gate::authorize('shipment.view');

        $payment = Payment::findOrFail($subjectId);

        if ($payment->kind !== 'collection' || $payment->shipment_id === null) {
            throw ValidationException::withMessages(['subject_id' => 'الإيصال بيتطلع لحركة تحصيل بس']);
        }

        AccessScope::apply(Shipment::query(), $user, 'shipment.view')->findOrFail($payment->shipment_id);

        return $payment;
    }

    public function serial(Model $subject): string
    {
        return $this->serials->next('receipt');
    }

    public function data(Model $subject): array
    {
        $shipment = Shipment::with('customer', 'installments')->findOrFail($subject->shipment_id);
        $treasury = Treasury::findOrFail($subject->treasury_id);
        $currencyCode = $this->currencyCode($subject->currency_id);
        $isAgent = $shipment->agent_id !== null;

        return [
            'payment' => $subject,
            'shipment' => $shipment,
            'cashier' => User::find($subject->created_by),
            'method' => self::METHODS[$treasury->method] ?? $treasury->method,
            'currencyName' => $this->currencyName($subject->currency_id),
            'amountWords' => ArabicMoney::words((string) $subject->amount, $currencyCode),
            'isAgent' => $isAgent,
            // The company-side totals are hidden on receipts for external offices' customers.
            'balance' => $isAgent ? null : $this->shipments->balance($shipment),
            'shipmentCurrency' => $this->currencyName($shipment->currency_id),
            'openInstallments' => $isAgent ? collect() : $shipment->installments->whereIn('status', ['due', 'overdue'])->values(),
            'trackingUrl' => rtrim(config('gomana.tracking_url'), '/').'/'.$shipment->tracking_code,
        ];
    }
}
