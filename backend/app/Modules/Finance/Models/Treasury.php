<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\MasterData\Models\Currency;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Treasury extends Model
{
    use Auditable;

    protected $table = 'treasuries';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
