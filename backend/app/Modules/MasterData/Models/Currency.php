<?php

namespace App\Modules\MasterData\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $table = 'currencies';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
