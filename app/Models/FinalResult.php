<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A computed course result. Locked rows are frozen (ADR-005): the only change ever written to one
 * is is_current = false when a correction, backed by an administrative order, supersedes it.
 */
class FinalResult extends Model
{
    use Audited;

    protected $fillable = ['enrollment_id', 'exam_round', 'version', 'total_mark', 'rounded_mark', 'grade_code', 'grade_name_ar', 'grade_points', 'passed', 'outcome', 'credits_earned', 'policy_snapshot', 'input_hash', 'status', 'supersedes_id', 'administrative_order_id', 'is_current', 'computed_by', 'approved_by', 'approved_at', 'locked_at'];

    protected $casts = [
        'policy_snapshot' => 'array',
        'passed' => 'boolean',
        'is_current' => 'boolean',
        'total_mark' => 'float',
        'rounded_mark' => 'float',
        'credits_earned' => 'float',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FinalResult $r) {
            if ($r->getOriginal('status') === 'locked' && array_keys($r->getDirty()) !== ['is_current']) {
                throw new LogicException('A locked result cannot be changed; issue a correction with an administrative order.');
            }
        });
        static::deleting(function (FinalResult $r) {
            if ($r->status !== 'draft') {
                throw new LogicException('Only draft results can be removed.');
            }
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AdministrativeOrder::class, 'administrative_order_id');
    }

    public function isOfficial(): bool
    {
        foreach ($this->policy_snapshot ?? [] as $p) {
            if (! ($p['is_official'] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
