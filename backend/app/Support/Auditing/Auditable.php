<?php

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;

/**
 * Records created/updated/deleted events in audit_logs automatically (AU-01).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditRecorder::record('created', $model, null, $model->getAttributes()));

        static::updated(function (Model $model): void {
            $after = $model->getChanges();
            $before = array_intersect_key($model->getOriginal(), $after);
            AuditRecorder::record('updated', $model, $before, $after);
        });

        static::deleted(fn (Model $model) => AuditRecorder::record('deleted', $model, $model->getAttributes(), null));
    }
}
