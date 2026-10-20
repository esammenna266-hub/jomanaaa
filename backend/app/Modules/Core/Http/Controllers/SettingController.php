<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('masterdata.view');

        return response()->json(['data' => Setting::orderBy('key')->get()]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        Gate::authorize('masterdata.manage');

        $data = $request->validate(['value' => ['required', 'string', 'max:255']]);

        $setting = Setting::findOrFail($key);
        $setting->update(['value' => $data['value']]);

        return response()->json(['data' => $setting->refresh()]);
    }
}
