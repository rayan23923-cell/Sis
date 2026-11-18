<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    protected $fillable = ['department_id', 'code', 'name_ar', 'name_en', 'credit_hours', 'ects', 'theory_hours', 'practical_hours', 'is_active'];

    protected $casts = ['credit_hours' => 'float', 'ects' => 'float', 'is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->collegeScope() === null ? $q : $q->whereHas('department', fn ($d) => $d->visibleTo($user));
    }
}
