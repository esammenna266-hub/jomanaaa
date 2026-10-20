<?php

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferItem extends Model
{
    protected $table = 'transfer_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $appends = ['difference'];

    protected function casts(): array
    {
        return ['sent_count' => 'decimal:2', 'received_count' => 'decimal:2'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * received minus sent (WH-04): negative is a shortage, positive an overage, null until received.
     */
    protected function getDifferenceAttribute(): ?string
    {
        if ($this->received_count === null) {
            return null;
        }

        return number_format((float) $this->received_count - (float) $this->sent_count, 2, '.', '');
    }
}
