<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationRound extends Model
{
    use Audited;

    protected $fillable = ['evaluation_form_id', 'academic_term_id', 'college_id', 'name', 'opens_on', 'closes_on', 'min_responses', 'created_by'];

    protected $casts = ['opens_on' => 'date', 'closes_on' => 'date', 'min_responses' => 'integer'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class, 'evaluation_form_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function isOpen(): bool
    {
        return ! today()->lt($this->opens_on) && ! today()->gt($this->closes_on);
    }

    public function isClosed(): bool
    {
        return today()->gt($this->closes_on);
    }

    public function state(): string
    {
        return $this->isOpen() ? 'open' : ($this->isClosed() ? 'closed' : 'upcoming');
    }
}
