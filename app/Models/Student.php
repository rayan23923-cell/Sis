<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use Audited;

    public const SHIFTS = ['morning' => 'صباحي', 'evening' => 'مسائي'];

    public const CHANNELS = ['central' => 'القبول المركزي', 'parallel' => 'الدراسة الموازية', 'evening' => 'الدراسة المسائية', 'transfer' => 'انتقال', 'other' => 'أخرى'];

    /** Evening admission means evening study; every other channel defaults to morning. */
    public static function shiftFor(?string $channel, ?string $shift = null): string
    {
        return in_array($shift, array_keys(self::SHIFTS), true) ? $shift : ($channel === 'evening' ? 'evening' : 'morning');
    }

    public const STATUSES = [
        'active' => 'مستمر',
        'postponed' => 'مؤجل',
        'hosted' => 'مستضاف',
        'transferred_out' => 'منقول',
        'dismissed' => 'مرقن قيده',
        'withdrawn' => 'منسحب',
        'graduated' => 'متخرج',
    ];

    protected $fillable = ['university_number', 'national_id', 'name_ar', 'name_en', 'mother_name_ar', 'birth_date', 'gender', 'phone', 'email', 'status', 'admission_year_id', 'admission_channel', 'study_shift', 'user_id'];

    protected $casts = ['birth_date' => 'date'];

    public function programs(): HasMany
    {
        return $this->hasMany(StudentProgram::class)->orderByDesc('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentProgram(): HasOne
    {
        return $this->hasOne(StudentProgram::class)->where('is_current', true)->latestOfMany();
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(StudentStatusChange::class)->orderByDesc('effective_on')->orderByDesc('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(StudentInvoice::class)->latest('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function admissionYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'admission_year_id');
    }

    /** Students whose current program sits in a college or department the user may see. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->collegeScope() === null) {
            return $q;
        }

        return $q->whereHas('currentProgram.program', fn ($p) => $p->visibleTo($user));
    }
}
