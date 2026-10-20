<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use Auditable;

    protected $table = 'vehicles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
