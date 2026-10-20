<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    protected $guarded = ['id'];

    public $timestamps = false;
}
