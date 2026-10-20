<?php

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $table = 'shipment_items';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['qty' => 'decimal:2', 'per_pack' => 'decimal:2', 'weight_kg' => 'decimal:2'];
    }
}
