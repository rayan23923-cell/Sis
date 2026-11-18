<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    use Audited;

    public const KINDS = ['lecture' => 'نظري', 'lab' => 'عملي'];

    protected $fillable = ['course_offering_id', 'held_on', 'period', 'hours', 'kind', 'topic', 'recorded_by'];

    protected $casts = ['held_on' => 'date', 'hours' => 'float', 'period' => 'integer'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
