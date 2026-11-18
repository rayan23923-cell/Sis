<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProgram extends Model
{
    use Audited;

    protected $fillable = ['student_id', 'program_id', 'curriculum_version_id', 'stage', 'start_year_id', 'end_year_id', 'is_current', 'end_reason', 'administrative_order_id'];

    protected $casts = ['is_current' => 'boolean', 'stage' => 'integer'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class, 'curriculum_version_id');
    }
}
