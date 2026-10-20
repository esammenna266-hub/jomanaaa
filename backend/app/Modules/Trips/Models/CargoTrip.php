<?php

namespace App\Modules\Trips\Models;

use App\Modules\MasterData\Models\BorderPoint;
use App\Modules\MasterData\Models\ClearingAgent;
use App\Modules\MasterData\Models\Driver;
use App\Modules\MasterData\Models\Vehicle;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A truck journey carrying several shipments (the manifest is the list of its shipments).
 */
class CargoTrip extends Model
{
    use Auditable;

    /** Journey stages in order; a trip only moves forward through them. */
    public const STAGES = ['loaded', 'departed_cairo', 'at_aswan', 'crossing_border', 'in_sudan', 'arrived', 'completed'];

    /** Shipment status that follows each stage (completed leaves shipments as they are). */
    public const SHIPMENT_STATUS = [
        'loaded' => 'loaded',
        'departed_cairo' => 'in_transit',
        'at_aswan' => 'in_transit',
        'crossing_border' => 'in_transit',
        'in_sudan' => 'in_transit',
        'arrived' => 'arrived',
    ];

    protected $table = 'cargo_trips';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['departed_at' => 'datetime', 'arrived_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function borderPoint(): BelongsTo
    {
        return $this->belongsTo(BorderPoint::class);
    }

    public function clearingAgent(): BelongsTo
    {
        return $this->belongsTo(ClearingAgent::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(TripExpense::class)->orderBy('id');
    }

    public function delays(): HasMany
    {
        return $this->hasMany(TripDelay::class)->orderBy('id');
    }

    public function undertaking(): HasOne
    {
        return $this->hasOne(DriverUndertaking::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TripStageEvent::class, 'trip_id')->where('trip_type', 'cargo')->orderBy('happened_at')->orderBy('id');
    }
}
