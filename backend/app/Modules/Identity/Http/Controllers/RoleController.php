<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    /**
     * Roles for the user form's dropdown.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('user.manage');

        return response()->json(['data' => Role::orderBy('id')->get(['id', 'code', 'name_ar'])]);
    }
}
