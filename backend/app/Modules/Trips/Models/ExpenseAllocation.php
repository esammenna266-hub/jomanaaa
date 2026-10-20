<?php

namespace App\Modules\Trips\Models;

use App\Modules\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseAllocation extends Model
{
    protected $table = 'expense_allocations';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(TripExpense::class, 'trip_expense_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
