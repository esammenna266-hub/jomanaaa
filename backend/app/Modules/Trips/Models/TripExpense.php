<?php

namespace App\Modules\Trips\Models;

use App\Modules\Finance\Models\Payment;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripExpense extends Model
{
    use Auditable;

    protected $table = 'trip_expenses';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(CargoTrip::class, 'cargo_trip_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
    }
}
