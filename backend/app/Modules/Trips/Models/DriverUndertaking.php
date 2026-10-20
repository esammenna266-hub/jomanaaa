<?php

namespace App\Modules\Trips\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class DriverUndertaking extends Model
{
    use Auditable;

    protected $table = 'driver_undertakings';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    /** The stored path is internal: it is only exposed through a signed URL. */
    protected $hidden = ['fingerprint_path'];

    protected function casts(): array
    {
        return ['daily_penalty' => 'decimal:2', 'signed_at' => 'datetime'];
    }
}
