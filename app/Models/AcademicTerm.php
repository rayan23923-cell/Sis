<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicTerm extends Model
{
    public const STATUSES = ['planning', 'registration', 'teaching', 'exams', 'closed'];

    protected $fillable = ['academic_year_id', 'code', 'name_ar', 'name_en', 'starts_on', 'ends_on', 'status'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];

    public function year(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(CourseOffering::class);
    }

    public function label(): string
    {
        return $this->name_ar.' '."\u{2066}".$this->year?->name."\u{2069}";
    }
}
