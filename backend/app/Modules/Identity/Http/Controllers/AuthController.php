<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const MAX_FAILED_LOGINS = 5;

    private const LOCK_SECONDS = 900;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Guessing one account's password is slowed down whatever address the guesses come from.
        $lockKey = 'login:'.mb_strtolower($credentials['username']);

        if (RateLimiter::tooManyAttempts($lockKey, self::MAX_FAILED_LOGINS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lockKey) / 60);

            throw ValidationException::withMessages(['username' => "محاولات دخول غلط كتير، استنى {$minutes} دقيقة وحاول تاني"]);
        }

        $user = User::where('username', $credentials['username'])->first();

        if ($user === null || ! $user->active || ! Hash::check($credentials['password'], $user->password_hash)) {
            RateLimiter::hit($lockKey, self::LOCK_SECONDS);

            throw ValidationException::withMessages(['username' => 'اسم المستخدم أو كلمة المرور غير صحيحة']);
        }

        RateLimiter::clear($lockKey);
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken('api', ['*'], now()->addHours(12))->plainTextToken,
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غلط']);
        }

        $user->update(['password_hash' => $data['password'], 'must_change_password' => false]);

        // Anyone else holding a session of this account (a lost phone, a shared computer) is signed out.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($tokens) => $tokens->whereKeyNot($current->getKey()))->delete();

        return response()->json(['message' => 'تم تغيير كلمة المرور']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $user->loadMissing('role:id,code,name_ar', 'branch:id,name,country,timezone,is_main_warehouse', 'agent:id,name');

        return [
            ...$user->toArray(),
            'permissions' => $user->permissionScopes(),
        ];
    }
}
