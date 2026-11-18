<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    public const SYSTEMS = ['bologna', 'semester', 'annual'];

    protected $fillable = ['department_id', 'code', 'name_ar', 'name_en', 'study_system', 'degree', 'duration_years', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'duration_years' => 'integer'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(CurriculumVersion::class)->orderByDesc('version');
    }

    public function studentPrograms(): HasMany
    {
        return $this->hasMany(StudentProgram::class);
    }

    public function collegeId(): int
    {
        return $this->department->college_id;
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->collegeScope() === null ? $q : $q->whereHas('department', fn ($d) => $d->visibleTo($user));
    }
}
