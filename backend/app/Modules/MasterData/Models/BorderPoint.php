<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class BorderPoint extends Model
{
    use Auditable;

    protected $table = 'border_points';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
