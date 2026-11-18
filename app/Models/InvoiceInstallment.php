<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceInstallment extends Model
{
    protected $fillable = ['student_invoice_id', 'number', 'amount', 'due_on'];

    protected $casts = ['amount' => 'integer', 'number' => 'integer', 'due_on' => 'date'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(StudentInvoice::class, 'student_invoice_id');
    }
}
