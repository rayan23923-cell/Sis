<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentComponent extends Model
{
    protected $fillable = ['course_offering_id', 'code', 'name_ar', 'weight', 'max_mark', 'exam_round', 'sort'];

    protected $casts = ['weight' => 'float', 'max_mark' => 'float', 'exam_round' => 'integer'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }
}
