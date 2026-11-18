<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Payment extends Model
{
    use Audited;

    public const METHODS = ['cash' => 'نقداً', 'bank' => 'إيداع مصرفي', 'electronic' => 'دفع إلكتروني'];

    protected $fillable = ['receipt_number', 'student_invoice_id', 'student_id', 'amount', 'paid_on', 'method', 'reference', 'notes', 'received_by', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['amount' => 'integer', 'paid_on' => 'date', 'voided_at' => 'datetime'];

    protected static function booted(): void
    {
        // A receipt is never edited or deleted; a mistake is voided with a reason and a new receipt issued.
        static::updating(function (Payment $p) {
            if (array_diff(array_keys($p->getDirty()), ['voided_at', 'voided_by', 'void_reason', 'updated_at']) !== []) {
                throw new LogicException('A payment cannot be edited; void it and record a new one.');
            }
        });
        static::deleting(fn () => throw new LogicException('A payment cannot be deleted; void it instead.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StudentInvoice::class, 'student_invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
