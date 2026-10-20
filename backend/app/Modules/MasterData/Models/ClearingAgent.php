<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class ClearingAgent extends Model
{
    use Auditable;

    protected $table = 'clearing_agents';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
