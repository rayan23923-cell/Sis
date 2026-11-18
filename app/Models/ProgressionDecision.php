<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProgressionDecision extends Model
{
    use Audited;

    public const DECISIONS = [
        'promoted' => 'ناجح ومرفّع',
        'promoted_with_carried' => 'مرفّع مع التحميل',
        'repeat' => 'راسب (يعيد السنة)',
        'graduate' => 'متخرج',
    ];

    protected $fillable = ['student_program_id', 'academic_year_id', 'stage', 'gpa', 'courses_count', 'failed_count', 'credits_earned', 'failed_courses', 'decision', 'policy_snapshot', 'status', 'administrative_order_id', 'created_by', 'applied_at', 'decision_awards'];

    protected $casts = ['failed_courses' => 'array', 'policy_snapshot' => 'array', 'decision_awards' => 'array', 'gpa' => 'float', 'credits_earned' => 'float', 'applied_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (ProgressionDecision $d) {
            if ($d->getOriginal('status') === 'applied') {
                throw new LogicException('An applied decision cannot be changed.');
            }
        });
    }

    public function studentProgram(): BelongsTo
    {
        return $this->belongsTo(StudentProgram::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AdministrativeOrder::class, 'administrative_order_id');
    }
}
