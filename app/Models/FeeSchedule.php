<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeSchedule extends Model
{
    use Audited;

    protected $fillable = ['academic_year_id', 'name', 'college_id', 'program_id', 'admission_channel', 'study_shift', 'stage', 'amount', 'installments', 'is_active', 'source_reference', 'created_by'];

    protected $casts = ['installments' => 'array', 'is_active' => 'boolean', 'amount' => 'integer', 'stage' => 'integer'];

    public function year(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(StudentInvoice::class);
    }

    /** How specific the schedule is; the most specific match wins. */
    public function specificity(): int
    {
        return ($this->program_id ? 8 : ($this->college_id ? 4 : 0)) + ($this->admission_channel ? 2 : 0) + ($this->study_shift ? 1 : 0) + ($this->stage ? 16 : 0);
    }
}
