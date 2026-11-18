<?php

namespace App\Support;

/** lang/{locale}.json keyed by the Arabic source text (see resources/js/i18n/arabic-i18n.js). */
class Translations
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** @return array<string, string> */
    public static function for(string $locale): array
    {
        if ($locale === 'ar') {
            return [];
        }

        return self::$cache[$locale] ??= (function () use ($locale) {
            $path = lang_path($locale.'.json');

            return is_file($path) ? json_decode((string) file_get_contents($path), true) ?: [] : [];
        })();
    }

    /**
     * Translates one server-side string: exact match first, then with its numbers as :0, :1… placeholders.
     * Text that has no entry (names, free text) is returned unchanged.
     */
    public static function text(string $s, string $locale): string
    {
        $dict = self::for($locale);
        if ($dict === [] || ! preg_match('/[\x{0600}-\x{06FF}]/u', $s)) {
            return $s;
        }
        if (isset($dict[$s])) {
            return $dict[$s];
        }
        $nums = [];
        $key = preg_replace_callback('/\d+(?:[.\-]\d+)*/', function ($m) use (&$nums) {
            $nums[] = $m[0];

            return ':'.(count($nums) - 1);
        }, $s);
        if ($nums && isset($dict[$key])) {
            $out = $dict[$key];
            foreach ($nums as $i => $n) {
                $out = str_replace(':'.$i, $n, $out);
            }

            return $out;
        }

        return $s;
    }

    /** Walks Inertia props and translates every string value. */
    public static function deep(mixed $v, string $locale): mixed
    {
        if (is_string($v)) {
            return self::text($v, $locale);
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::deep($x, $locale);
            }
        }

        return $v;
    }
}
