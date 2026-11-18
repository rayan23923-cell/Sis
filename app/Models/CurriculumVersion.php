<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumVersion extends Model
{
    protected $fillable = ['program_id', 'version', 'name', 'effective_from_year_id', 'status', 'approval_reference', 'approved_at', 'approved_by'];

    protected $casts = ['approved_at' => 'datetime'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(CurriculumCourse::class)->orderBy('stage')->orderBy('term_no');
    }

    public function isLocked(): bool
    {
        return $this->status !== 'draft';
    }
}
