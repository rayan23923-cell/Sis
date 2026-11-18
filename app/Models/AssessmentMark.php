<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentMark extends Model
{
    use Audited;

    protected $fillable = ['enrollment_id', 'assessment_component_id', 'mark', 'is_absent', 'entered_by'];

    protected $casts = ['mark' => 'float', 'is_absent' => 'boolean'];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(AssessmentComponent::class, 'assessment_component_id');
    }
}
