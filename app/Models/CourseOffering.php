<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CourseOffering extends Model
{
    use Audited;

    public const STATUSES = [
        'open' => 'مفتوحة لإدخال الدرجات',
        'submitted' => 'مرفوعة للجنة',
        'approved' => 'مصادق عليها',
        'locked' => 'مقفلة',
    ];

    protected $attributes = ['section' => 'A', 'shift' => 'morning', 'capacity' => 60, 'current_round' => 1, 'status' => 'open'];

    protected $fillable = ['course_id', 'academic_term_id', 'program_id', 'stage', 'section', 'shift', 'teacher_id', 'capacity', 'current_round', 'status', 'is_evaluative'];

    protected $casts = ['is_evaluative' => 'boolean'];

    public function slots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class)->orderBy('day')->orderBy('starts_at');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The date academic policies are resolved on for this offering's results: the end of its academic
     * year, the same date progression, the master sheet and transcripts use. Never "today", so computing
     * a past year's result later still applies that year's policies (FR-AR-18).
     */
    public function policyDate(): Carbon
    {
        $this->loadMissing('term.year');

        return $this->term->year->ends_on->copy();
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(AssessmentComponent::class)->orderBy('exam_round')->orderBy('sort');
    }

    public function collegeId(): int
    {
        return $this->course->department->college_id;
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->collegeScope() === null) {
            // an explicit always-true condition, so a caller's ->orWhere(...) widens nothing instead of narrowing to it
            return $q->whereRaw('1 = 1');
        }
        if ($user->isOnlyTeacher()) {
            return $q->where('teacher_id', $user->id);
        }

        return $q->where(fn ($w) => $w->whereHas('course', fn ($c) => $c->visibleTo($user))->orWhere('teacher_id', $user->id));
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class)->orderBy('held_on')->orderBy('period');
    }
}
