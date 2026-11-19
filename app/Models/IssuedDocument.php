<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A printed document that anyone can verify by its QR code. Only revocation changes it afterwards. */
class IssuedDocument extends Model
{
    use Audited;

    public const TYPES = ['transcript' => 'كشف درجات', 'graduation' => 'وثيقة تخرج', 'enrollment' => 'تأييد استمرار بالدراسة'];

    protected $fillable = ['code', 'type', 'student_id', 'snapshot', 'content_hash', 'official', 'issued_by', 'revoked_at', 'revoked_by', 'revoke_reason'];

    protected $casts = ['snapshot' => 'array', 'official' => 'boolean', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (IssuedDocument $d) {
            $d->code ??= self::newCode();
            $d->content_hash ??= hash('sha256', json_encode($d->snapshot, JSON_UNESCAPED_UNICODE));
        });
        static::updating(function (IssuedDocument $d) {
            if (collect($d->getDirty())->keys()->diff(['revoked_at', 'revoked_by', 'revoke_reason', 'updated_at'])->isNotEmpty()) {
                throw new \LogicException('An issued document cannot be changed, only revoked.');
            }
        });
    }

    /** 12 unambiguous characters in groups of four, e.g. K7QF-2MXP-9RTA. */
    public static function newCode(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 12; $i++) {
                $raw .= 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0, 31)];
            }
            $code = implode('-', str_split($raw, 4));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function verifyUrl(): string
    {
        return route('verify', $this->code);
    }

    public function isValid(): bool
    {
        return $this->revoked_at === null;
    }

    public static function normalise(string $code): string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return Str::length($c) === 12 ? implode('-', str_split($c, 4)) : $code;
    }
}
