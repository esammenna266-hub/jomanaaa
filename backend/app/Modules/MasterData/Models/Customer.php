<?php

namespace App\Modules\MasterData\Models;

use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use Auditable, HasFactory;

    protected $table = 'customers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'whatsapp_verified_at' => 'datetime'];
    }
}
