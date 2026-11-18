<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentInvoice extends Model
{
    use Audited;

    public const STATUSES = ['open' => 'غير مسددة', 'paid' => 'مسددة', 'cancelled' => 'ملغاة'];

    protected $fillable = ['student_id', 'academic_year_id', 'fee_schedule_id', 'program_id', 'admission_channel', 'study_shift', 'stage', 'amount', 'discount', 'discount_reason', 'status', 'cancel_reason', 'issued_by'];

    protected $casts = ['amount' => 'integer', 'discount' => 'integer', 'stage' => 'integer'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(InvoiceInstallment::class)->orderBy('number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function net(): int
    {
        return max(0, $this->amount - $this->discount);
    }

    public function paid(): int
    {
        return (int) $this->payments->whereNull('voided_at')->sum('amount');
    }

    public function balance(): int
    {
        return $this->status === 'cancelled' ? 0 : max(0, $this->net() - $this->paid());
    }

    /**
     * Each installment with how much of it is paid. Payments settle installments in order.
     *
     * @return list<array{number: int, amount: int, due_on: string, paid: int, status: string}>
     */
    public function lines(): array
    {
        $left = $this->paid();
        $out = [];
        foreach ($this->installments as $i) {
            $paid = min($i->amount, $left);
            $left -= $paid;
            $status = $paid >= $i->amount ? 'paid' : ($i->due_on->isPast() && ! $i->due_on->isToday() ? 'overdue' : ($paid > 0 ? 'partial' : 'due'));
            $out[] = ['number' => $i->number, 'amount' => $i->amount, 'due_on' => $i->due_on->toDateString(), 'paid' => $paid, 'status' => $this->status === 'cancelled' ? 'cancelled' : $status];
        }

        return $out;
    }
}
