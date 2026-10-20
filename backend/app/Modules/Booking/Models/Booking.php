<?php

namespace App\Modules\Booking\Models;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Modules\Finance\Models\Payment;
use App\Modules\MasterData\Models\Customer;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use Auditable, HasFactory;

    protected $table = 'bookings';

    protected $guarded = ['id'];

    /** Internal cost to an external office: never shown to the passenger or printed. */
    protected $hidden = ['agent_price', 'active_seat'];

    protected function casts(): array
    {
        return ['sale_price' => 'decimal:2', 'agent_price' => 'decimal:2'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(PassengerTrip::class, 'trip_id');
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
        return $this->hasMany(Payment::class)->orderBy('id');
    }
}
