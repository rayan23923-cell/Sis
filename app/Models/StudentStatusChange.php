<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentStatusChange extends Model
{
    protected $fillable = ['student_id', 'from_status', 'to_status', 'effective_on', 'administrative_order_id', 'notes', 'created_by'];

    protected $casts = ['effective_on' => 'date'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(AdministrativeOrder::class, 'administrative_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
