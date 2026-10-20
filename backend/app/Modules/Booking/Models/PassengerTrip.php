<?php

namespace App\Modules\Booking\Models;

use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bus, flight or ship departure with a seat quota (table `trips`). Cargo trucks live in CargoTrip.
 */
class PassengerTrip extends Model
{
    use Auditable, HasFactory;

    /** Booking statuses that occupy a seat. */
    public const LIVE_BOOKINGS = ['reserved', 'paid', 'travelled'];

    /** Seats can still be sold in these statuses. */
    public const SELLABLE = ['open', 'express_locked'];

    protected $table = 'trips';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'depart_at' => 'datetime',
            'checkin_at' => 'datetime',
            'express_cutoff_at' => 'datetime',
            'price' => 'decimal:2',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'trip_id');
    }

    public function liveBookings(): HasMany
    {
        return $this->bookings()->whereIn('status', self::LIVE_BOOKINGS);
    }

    /** Express (cargo) shipments linked to this trip that still hold an express seat. */
    public function liveExpressShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'bus_trip_id')->where('status', '<>', 'cancelled');
    }

    /**
     * The express cutoff has passed (BS-09). Read from the clock as well as the status, so the scheduled command
     * running a few minutes late never lets a seat be linked after the cutoff, and a departed trip keeps the seats
     * released before it left.
     */
    public function isExpressLocked(): bool
    {
        return $this->status === 'express_locked'
            || ($this->express_cutoff_at !== null && $this->express_cutoff_at->isPast());
    }

    public function expressUsed(): int
    {
        return (int) ($this->live_express_shipments_count ?? $this->liveExpressShipments()->count());
    }

    /** Express seats still open for new shipments: none once the cutoff has passed. */
    public function expressFree(): int
    {
        return $this->isExpressLocked() ? 0 : max($this->express_seats - $this->expressUsed(), 0);
    }

    /** Unused express seats handed over to passengers after the cutoff. */
    public function releasedExpressSeats(): int
    {
        return $this->isExpressLocked() ? max($this->express_seats - $this->expressUsed(), 0) : 0;
    }

    /** Seats numbered 1..N that passengers can book: the passenger quota plus any released express seats. */
    public function sellableSeats(): int
    {
        return $this->passenger_seats + $this->releasedExpressSeats();
    }
}
