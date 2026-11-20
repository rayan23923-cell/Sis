<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The library's answer for one student, as of the last sync. */
class LibraryStatus extends Model
{
    public $timestamps = false;

    protected $fillable = ['student_id', 'is_member', 'active_loans', 'overdue_loans', 'fines_due', 'synced_at'];

    protected $casts = ['is_member' => 'boolean', 'synced_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isClear(): bool
    {
        return $this->active_loans === 0 && $this->fines_due === 0;
    }

    /** For a page: null when the library connector is off. */
    public static function summaryFor(int $studentId): ?array
    {
        if (! Connector::active('library', 'clearance')) {
            return null;
        }
        $s = self::where('student_id', $studentId)->first();

        return $s ? $s->summary() : ['known' => false];
    }

    public function summary(): array
    {
        return [
            'known' => true, 'member' => $this->is_member, 'clear' => $this->isClear(), 'loans' => $this->active_loans,
            'overdue' => $this->overdue_loans, 'fines' => $this->fines_due,
            'at' => $this->synced_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
        ];
    }
}
