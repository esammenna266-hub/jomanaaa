<?php

namespace App\Modules\Trips\Models;

use Illuminate\Database\Eloquent\Model;

class TripStageEvent extends Model
{
    protected $table = 'trip_stage_events';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['happened_at' => 'datetime', 'whatsapp_sent' => 'boolean'];
    }
}
