<?php

namespace App\Modules\Booking\Models;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Payment;
use App\Modules\MasterData\Models\Customer;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hotel or car booking: free-form details and one price (open point 14).
 */
class ServiceBooking extends Model
{
    use Auditable;

    protected $table = 'service_bookings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'service_booking_id')->orderBy('id');
    }
}
