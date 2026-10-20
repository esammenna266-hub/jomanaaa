<?php

namespace App\Modules\Trips\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class TripDelay extends Model
{
    use Auditable;

    protected $table = 'trip_delays';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['cost' => 'decimal:2'];
    }
}
