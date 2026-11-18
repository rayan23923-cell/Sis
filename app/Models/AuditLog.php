<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'reason', 'ip', 'user_agent'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit trail cannot be edited.'));
        static::deleting(fn () => throw new LogicException('The audit trail cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
