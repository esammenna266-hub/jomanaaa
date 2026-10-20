<?php

namespace App\Modules\Documents\Definitions;

use Illuminate\Validation\ValidationException;

final class DocumentRegistry
{
    /** @var array<string, class-string<DocumentDefinition>> */
    private const DEFINITIONS = [
        'shipment_form' => ShipmentFormDocument::class,
        'receipt' => ReceiptDocument::class,
        'waybill' => WaybillDocument::class,
        'manifest' => ManifestDocument::class,
        'driver_undertaking' => DriverUndertakingDocument::class,
        'bus_ticket' => BusTicketDocument::class,
        'passenger_manifest' => PassengerManifestDocument::class,
    ];

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function get(string $type): DocumentDefinition
    {
        $class = self::DEFINITIONS[$type] ?? throw ValidationException::withMessages(['type' => 'نوع المستند غير مدعوم']);

        return app($class);
    }
}
