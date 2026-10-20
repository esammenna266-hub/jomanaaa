<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class Destination extends Model
{
    use Auditable;

    protected $table = 'destinations';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
