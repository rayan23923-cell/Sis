<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Enrollment extends Model
{
    use Audited;

    protected $fillable = ['student_id', 'course_offering_id', 'student_program_id', 'attempt_no', 'is_carried', 'status'];

    protected $casts = ['is_carried' => 'boolean'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function studentProgram(): BelongsTo
    {
        return $this->belongsTo(StudentProgram::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(AssessmentMark::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(FinalResult::class)->orderByDesc('exam_round')->orderByDesc('version');
    }

    public function currentResult(): HasOne
    {
        // The live result of the latest exam round (round 2 overrides round 1).
        return $this->hasOne(FinalResult::class)->ofMany(['exam_round' => 'max', 'id' => 'max'], fn ($q) => $q->where('is_current', true));
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
