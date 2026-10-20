<?php

namespace App\Modules\Finance\Models;

use App\Modules\Shipping\Models\Shipment;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installment extends Model
{
    use Auditable;

    protected $table = 'installments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'reminded_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
