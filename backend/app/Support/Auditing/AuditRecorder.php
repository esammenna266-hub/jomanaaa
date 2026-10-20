<?php

namespace App\Support\Auditing;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class AuditRecorder
{
    private const NEVER_LOGGED = ['password_hash', 'password', 'remember_token', 'updated_at'];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, Model $model, ?array $before, ?array $after): void
    {
        $before = self::clean($before, $model);
        $after = self::clean($after, $model);

        if ($action === 'updated' && $after === []) {
            return;
        }

        $actor = auth()->user();

        DB::table('audit_logs')->insert([
            'user_id' => $actor instanceof User ? $actor->id : null,
            'branch_id' => $actor instanceof User ? $actor->branch_id : null,
            'action' => $action,
            'entity' => $model->getTable(),
            'entity_id' => $model->getKey(),
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private static function clean(?array $values, Model $model): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_diff_key($values, array_flip([...self::NEVER_LOGGED, ...$model->getHidden()]));
    }
}
