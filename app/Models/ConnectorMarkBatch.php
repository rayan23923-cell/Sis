<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Marks sent by the exams system for one component of one course; the teacher accepts or rejects them. */
class ConnectorMarkBatch extends Model
{
    use Audited;

    public const STATUSES = ['pending' => 'بانتظار التدريسي', 'applied' => 'أُدخلت في السجل', 'rejected' => 'مرفوضة', 'superseded' => 'استُبدلت بإرسال أحدث'];

    protected $fillable = ['connector', 'reference', 'course_offering_id', 'assessment_component_id', 'exam_round', 'rows', 'unknown', 'status', 'reviewed_by', 'reviewed_at', 'note'];

    protected $casts = ['rows' => 'array', 'unknown' => 'array', 'reviewed_at' => 'datetime'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
