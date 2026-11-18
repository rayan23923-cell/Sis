<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    public const STATUSES = ['present' => 'حاضر', 'absent' => 'غائب', 'excused' => 'غائب بعذر', 'late' => 'متأخر'];

    protected $fillable = ['attendance_session_id', 'enrollment_id', 'status', 'note'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'attendance_session_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
