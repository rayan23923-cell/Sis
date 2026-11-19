<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    use Audited;

    public const TYPES = ['transcript' => 'كشف درجات', 'enrollment' => 'تأييد استمرار بالدراسة', 'graduation' => 'وثيقة تخرج'];

    public const STATUSES = ['submitted' => 'قيد المراجعة', 'ready' => 'جاهزة', 'delivered' => 'مُسلّمة', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة'];

    protected $fillable = ['student_id', 'type', 'addressed_to', 'purpose', 'notes', 'status', 'response', 'issued_document_id', 'file_path', 'handled_by', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IssuedDocument::class, 'issued_document_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
