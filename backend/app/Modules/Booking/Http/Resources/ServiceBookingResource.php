<?php

namespace App\Modules\Booking\Http\Resources;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hotel or car booking with its customer and the money collected for it.
 */
class ServiceBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->resource->loadMissing('customer:id,name,phone', 'payments');

        $paid = $booking->payments->reduce(
            fn (BigDecimal $sum, $payment) => $payment->direction === 'in' ? $sum->plus($payment->amount) : $sum->minus($payment->amount),
            BigDecimal::zero(),
        );

        return [...$booking->toArray(), 'paid' => (string) $paid->toScale(2)];
    }
}
