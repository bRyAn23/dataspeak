<?php

namespace App\Support;

use App\Services\Reports\DisplayLabels;

/** FEAT-I18N-003 report and interface languages: English, Thai, Simplified Chinese, Burmese. */
final class Locale
{
    public const SUPPORTED = ['en', 'th', 'zh', 'my'];

    public const NAMES = ['en' => 'English', 'th' => 'Thai', 'zh' => 'Simplified Chinese', 'my' => 'Burmese'];

    private const SCRIPTS = ['th' => '\p{Thai}', 'zh' => '\p{Han}', 'my' => '\p{Myanmar}'];

    private const EXTRA = ['zh' => '\x{3000}-\x{303F}\x{FF00}-\x{FFEF}']; // CJK punctuation and full-width forms

    public static function normalize(?string $value, string $default = 'en'): string
    {
        $value = strtolower(substr(trim((string) $value), 0, 2));

        return in_array($value, self::SUPPORTED, true) ? $value : $default;
    }

    public static function name(string $locale): string
    {
        return self::NAMES[self::normalize($locale)] ?? 'English';
    }

    /** True when the text contains at least one letter of the locale's script (Latin letters for English). */
    public static function hasScript(string $text, string $locale): bool
    {
        $locale = self::normalize($locale);
        $pattern = $locale === 'en' ? '[A-Za-z\x{00C0}-\x{024F}]' : self::SCRIPTS[$locale];

        return preg_match('/'.$pattern.'/u', $text) === 1;
    }

    /** True when the text uses only the locale's script plus Latin letters, digits and punctuation. */
    public static function allowed(string $text, string $locale): bool
    {
        $locale = self::normalize($locale);
        if ($locale !== 'en') {
            $text = preg_replace('/['.self::SCRIPTS[$locale].(self::EXTRA[$locale] ?? '').']/u', '', $text) ?? $text;
        }

        return DisplayLabels::isLatin($text);
    }

    /** Allowed script and, outside English, actually written in the language (unless it has no letters at all). */
    public static function inLanguage(string $text, string $locale): bool
    {
        $locale = self::normalize($locale);
        if (! self::allowed($text, $locale)) {
            return false;
        }
        if ($locale === 'en' || preg_match('/\p{L}/u', $text) !== 1) {
            return true;
        }

        return self::hasScript($text, $locale);
    }
}
