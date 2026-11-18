<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CurriculumCourse extends Model
{
    protected $fillable = ['curriculum_version_id', 'course_id', 'stage', 'term_no', 'is_mandatory', 'credit_hours', 'ects', 'specialty_rank'];

    protected $casts = ['is_mandatory' => 'boolean', 'credit_hours' => 'float', 'ects' => 'float'];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class, 'curriculum_version_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'curriculum_course_prerequisites', 'curriculum_course_id', 'prerequisite_course_id');
    }
}
