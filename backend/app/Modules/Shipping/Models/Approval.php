<?php

namespace App\Modules\Shipping\Models;

use App\Modules\Identity\Models\User;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use Auditable;

    protected $table = 'approvals';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    /** Only meaningful while subject_type is "shipment" (the only subject so far). */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'subject_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
