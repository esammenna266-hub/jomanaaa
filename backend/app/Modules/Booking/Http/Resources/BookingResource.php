<?php

namespace App\Modules\Booking\Http\Resources;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking with its trip and the money collected for it. The office's cost price is never included.
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->resource->loadMissing('trip:id,kind,route,depart_at,status', 'payments');

        $paid = $booking->payments->reduce(
            fn (BigDecimal $sum, $payment) => $payment->direction === 'in' ? $sum->plus($payment->amount) : $sum->minus($payment->amount),
            BigDecimal::zero(),
        );

        return [...$booking->toArray(), 'paid' => (string) $paid->toScale(2)];
    }
}
