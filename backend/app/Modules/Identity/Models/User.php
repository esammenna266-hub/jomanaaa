<?php

namespace App\Modules\Identity\Models;

use App\Modules\Core\Models\Agent;
use App\Modules\Core\Models\Branch;
use App\Support\Auditing\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory;

    /** Widest scope wins when a user holds a permission through several grants. */
    private const SCOPE_RANK = ['own' => 1, 'branch' => 2, 'all' => 3];

    protected $table = 'users';

    protected $guarded = ['id'];

    protected $hidden = ['password_hash'];

    /** @var array<string, string>|null */
    private ?array $permissionScopes = null;

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Stopping an account ends its sessions straight away.
        static::updated(function (User $user): void {
            if ($user->wasChanged('active') && ! $user->active) {
                $user->tokens()->delete();
            }
        });
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Permission code => scope ('all' | 'branch' | 'own'), merged from the role and per-user grants.
     *
     * @return array<string, string>
     */
    public function permissionScopes(): array
    {
        if ($this->permissionScopes !== null) {
            return $this->permissionScopes;
        }

        $fromRole = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $this->role_id)
            ->select('p.code', 'rp.scope');

        $rows = DB::table('user_permissions as up')
            ->join('permissions as p', 'p.id', '=', 'up.permission_id')
            ->where('up.user_id', $this->id)
            ->select('p.code', 'up.scope')
            ->unionAll($fromRole)
            ->get();

        $scopes = [];
        foreach ($rows as $row) {
            $current = $scopes[$row->code] ?? null;
            if ($current === null || self::SCOPE_RANK[$row->scope] > self::SCOPE_RANK[$current]) {
                $scopes[$row->code] = $row->scope;
            }
        }

        return $this->permissionScopes = $scopes;
    }

    public function scopeFor(string $permission): ?string
    {
        return $this->permissionScopes()[$permission] ?? null;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->scopeFor($permission) !== null;
    }
}
