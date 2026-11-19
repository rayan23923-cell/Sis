<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Str;
use Mpdf\QrCode\Output\Svg;
use Mpdf\QrCode\QrCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step sign-in with an authenticator app (TOTP: Google Authenticator, Microsoft Authenticator…).
 * Works with no internet: the code is computed on the phone from a shared secret and the clock.
 */
class TwoFactor
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    /** Starts (or restarts) the set-up: a new secret, not active until confirmed with a code. */
    public function begin(User $user): void
    {
        $user->forceFill(['two_factor_secret' => $this->engine->generateSecretKey(32), 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
    }

    /** @return list<string> the recovery codes, shown once */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! $this->check($user->two_factor_secret, $code)) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
    }

    /** A code from the app, or a one-time recovery code (which is then used up). */
    public function verify(User $user, string $input): bool
    {
        $input = trim(strtr($input, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        if (! $user->hasTwoFactor()) {
            return false;
        }
        if (preg_match('/^\d{6}$/', str_replace(' ', '', $input))) {
            return $this->check($user->two_factor_secret, str_replace(' ', '', $input));
        }
        $hash = hash('sha256', strtolower($input));
        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function otpauthUri(User $user): string
    {
        return $this->engine->getQRCodeUrl(config('app.name', 'SIS'), $user->email, (string) $user->two_factor_secret);
    }

    /** The set-up QR code as inline SVG (rendered on the server, no external service). */
    public function qrSvg(User $user): string
    {
        return (new Svg)->output(new QrCode($this->otpauthUri($user), QrCode::ERROR_CORRECTION_MEDIUM), 200, 'white', 'black');
    }

    private function check(string $secret, string $code): bool
    {
        // a used code cannot be replayed within its window
        $key = 'totp:'.hash('sha256', $secret).':'.$code;
        if (cache()->has($key)) {
            return false;
        }
        $ok = (bool) $this->engine->verifyKey($secret, $code, 1);
        if ($ok) {
            cache()->put($key, true, 120);
        }

        return $ok;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }
}
