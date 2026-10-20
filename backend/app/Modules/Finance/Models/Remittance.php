<?php

namespace App\Modules\Finance\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash handover from a branch treasury to another treasury (AC-06).
 */
class Remittance extends Model
{
    use Auditable;

    protected $table = 'remittances';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'sent_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function fromTreasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'from_treasury_id');
    }

    public function toTreasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'to_treasury_id');
    }
}
