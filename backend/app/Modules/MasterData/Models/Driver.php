<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use Auditable;

    protected $table = 'drivers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_international' => 'boolean', 'id_issue_date' => 'date', 'id_expiry_date' => 'date'];
    }
}
