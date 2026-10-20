<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class SeatLayout extends Model
{
    use Auditable;

    protected $table = 'seat_layouts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['layout_json' => 'array'];
    }
}
