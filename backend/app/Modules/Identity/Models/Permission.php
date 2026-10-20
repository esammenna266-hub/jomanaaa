<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'permissions';

    protected $guarded = ['id'];

    public $timestamps = false;
}
