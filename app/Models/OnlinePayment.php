<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnlinePayment extends Model
{
    use Audited;

    public const STATUSES = [
        'pending' => 'بانتظار الدفع', 'paid' => 'مدفوعة', 'failed' => 'فشلت', 'expired' => 'انتهت مهلتها',
        'cancelled' => 'ألغيت', 'review' => 'تحتاج مراجعة',
    ];

    protected $fillable = ['uuid', 'student_invoice_id', 'student_id', 'gateway', 'amount', 'status', 'external_id', 'redirect_url', 'display', 'message', 'payment_id', 'created_by', 'expires_at', 'checked_at', 'settled_at', 'reviewed_by'];

    protected $casts = ['amount' => 'integer', 'display' => 'array', 'expires_at' => 'datetime', 'checked_at' => 'datetime', 'settled_at' => 'datetime'];

    protected $hidden = ['redirect_url'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StudentInvoice::class, 'student_invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'expired'], true);
    }
}
