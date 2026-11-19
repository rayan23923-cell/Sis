<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Room extends Model
{
    protected $fillable = ['college_id', 'code', 'name_ar', 'capacity', 'type', 'is_active'];

    public const TYPES = ['lecture' => 'قاعة محاضرات', 'lab' => 'مختبر', 'hall' => 'مدرج', 'studio' => 'مرسم/ورشة'];

    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }
}
