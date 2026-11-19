<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetableSlot extends Model
{
    use Audited;

    /** The Iraqi week: Saturday first; Friday is off. */
    public const DAYS = [0 => 'السبت', 1 => 'الأحد', 2 => 'الاثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس'];

    public const KINDS = ['lecture' => 'نظري', 'lab' => 'عملي'];

    protected $fillable = ['course_offering_id', 'day', 'starts_at', 'ends_at', 'room_id', 'kind', 'created_by'];

    protected $casts = ['day' => 'integer'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function minutes(): int
    {
        return (int) ((strtotime($this->ends_at) - strtotime($this->starts_at)) / 60);
    }
}
