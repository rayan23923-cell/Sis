<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdministrativeOrder extends Model
{
    use Audited;

    public const TYPES = [
        'admission' => 'قبول',
        'postponement' => 'تأجيل',
        'transfer' => 'نقل',
        'hosting' => 'استضافة',
        'dismissal' => 'ترقين قيد',
        'reinstatement' => 'إعادة قيد',
        'withdrawal' => 'انسحاب',
        'program_change' => 'تغيير قسم/برنامج',
        'result_correction' => 'تصحيح نتيجة',
        'other' => 'أخرى',
    ];

    protected $fillable = ['number', 'issued_on', 'type', 'title', 'body', 'issued_by_authority', 'college_id', 'created_by'];

    protected $casts = ['issued_on' => 'date'];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function reference(): string
    {
        return $this->number.' في '.$this->issued_on->format('Y/m/d');
    }
}
