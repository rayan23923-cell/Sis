<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    protected $fillable = ['university_id', 'code', 'name_ar', 'name_en', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $ids = $user->collegeScope();
        if ($ids === null) {
            return $q;
        }
        $viaDepartments = Department::whereIn('id', $user->departmentScope() ?? [])->pluck('college_id')->all();

        return $q->whereIn('colleges.id', array_unique([...$ids, ...$viaDepartments]));
    }
}
