<?php

namespace App\Modules\Documents\Definitions;

use App\Modules\Core\Models\Branch;
use App\Modules\Identity\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * استمارة شحن: the shipment form, numbered with the shipment's own serial.
 */
final class ShipmentFormDocument extends DocumentDefinition
{
    public function type(): string
    {
        return 'shipment_form';
    }

    public function paper(): string
    {
        return 'A4';
    }

    public function view(): string
    {
        return 'documents.shipment-form';
    }

    public function title(): string
    {
        return 'استمارة شحن';
    }

    public function subjectType(): string
    {
        return 'shipment';
    }

    public function find(int $subjectId): Model
    {
        return Shipment::findOrFail($subjectId);
    }

    public function resolve(int $subjectId, User $user): Model
    {
        Gate::authorize('shipment.view');

        return AccessScope::apply(Shipment::query(), $user, 'shipment.view')->findOrFail($subjectId);
    }

    public function serial(Model $subject): string
    {
        return $subject->serial_no;
    }

    public function data(Model $subject): array
    {
        $subject->load('customer', 'items', 'destination', 'borderPoint', 'clearingAgent');
        $isAgent = $subject->agent_id !== null;

        return [
            'shipment' => $subject,
            'branch' => Branch::find($subject->branch_id),
            'currency' => $this->currencyName($subject->currency_id),
            // External offices' customers only ever see the sale price, never the company price (AG-03).
            'isAgent' => $isAgent,
            'trackingUrl' => rtrim(config('gomana.tracking_url'), '/').'/'.$subject->tracking_code,
        ];
    }
}
