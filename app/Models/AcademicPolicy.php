<?php

namespace App\Models;

use App\AcademicRules\Policy;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AcademicPolicy extends Model
{
    use Audited;

    public const TYPES = [
        'grade_scale' => 'سلّم التقديرات',
        'rounding' => 'التقريب',
        'total' => 'احتساب الدرجة الكلية',
        'rounds' => 'الأدوار الامتحانية',
        'gpa' => 'المعدل',
        'progression' => 'الترفيع والتحميل',
        'attendance' => 'الغياب والإنذارات',
    ];

    protected $fillable = ['type', 'name_ar', 'version', 'executor', 'parameters', 'study_system', 'scope_type', 'scope_id', 'effective_from', 'effective_to', 'source_reference', 'approving_authority', 'is_official', 'status', 'changelog', 'created_by', 'approved_by', 'approved_at'];

    protected $casts = ['parameters' => 'array', 'is_official' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        // An approved policy is frozen; a change is a new version (FR-AR-03). Retiring is allowed.
        static::updating(function (AcademicPolicy $p) {
            if ($p->getOriginal('status') === 'approved') {
                $dirty = array_keys($p->getDirty());
                if (array_diff($dirty, ['status', 'effective_to', 'updated_at']) !== []) {
                    throw new LogicException('An approved policy cannot be edited; create a new version.');
                }
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toRule(): Policy
    {
        return new Policy($this->id, $this->type, $this->version, $this->executor, $this->parameters, $this->is_official, $this->source_reference);
    }
}
