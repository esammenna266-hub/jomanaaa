<?php

namespace App\Modules\Core\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use Auditable, HasFactory;

    protected $table = 'branches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main_warehouse' => 'boolean', 'active' => 'boolean'];
    }
}
