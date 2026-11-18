<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['college_id', 'code', 'name_ar', 'name_en', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $colleges = $user->collegeScope();
        if ($colleges === null) {
            return $q;
        }
        $departments = $user->departmentScope();

        return $q->where(function (Builder $w) use ($colleges, $departments) {
            $w->whereIn('departments.college_id', $colleges);
            if ($departments !== null) {
                $w->orWhereIn('departments.id', $departments);
            }
        });
    }
}
