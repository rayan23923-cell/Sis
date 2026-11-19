<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

class EvaluationForm extends Model
{
    use Audited;

    protected $fillable = ['name', 'questions', 'is_official', 'source_reference', 'is_active', 'created_by'];

    protected $casts = ['questions' => 'array', 'is_official' => 'boolean', 'is_active' => 'boolean'];

    /** @return list<int> indexes of the 1–5 questions */
    public function scaleIndexes(): array
    {
        return array_keys(array_filter($this->questions, fn ($q) => ($q['kind'] ?? 'scale') === 'scale'));
    }
}
