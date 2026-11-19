<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Support\AuditContext;

/**
 * Writes an audit row for every create, update and delete: who, when, from where, old and new values.
 */
trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(fn ($m) => static::writeAudit($m, 'created', null, $m->getAttributes()));
        static::updated(function ($m) {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            static::writeAudit($m, 'updated', $old, $changes);
        });
        static::deleted(fn ($m) => static::writeAudit($m, 'deleted', $m->getOriginal(), null));
    }

    protected static function writeAudit($model, string $action, ?array $old, ?array $new): void
    {
        $hidden = ['password', 'remember_token', 'created_at', 'updated_at', 'two_factor_secret', 'two_factor_recovery_codes', 'last_login_at', 'last_login_ip', 'display', 'redirect_url', 'checked_at', 'outgoing_token', 'incoming_token_hash', 'last_seen_at'];
        $clean = fn (?array $a) => $a === null ? null : array_diff_key($a, array_flip($hidden));

        AuditLog::create([
            'user_id' => AuditContext::userId(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $clean($old),
            'new_values' => $clean($new),
            'reason' => AuditContext::reason(),
            'ip' => AuditContext::ip(),
            'user_agent' => AuditContext::userAgent(),
        ]);
    }
}
