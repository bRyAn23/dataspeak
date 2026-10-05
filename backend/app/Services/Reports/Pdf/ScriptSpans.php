<?php

namespace App\Services\Reports\Pdf;

/**
 * dompdf uses one font per text run, so Thai and Myanmar text must be wrapped in spans that select a
 * font containing those glyphs. Everything else keeps the default Noto Sans. Output is HTML-escaped.
 */
class ScriptSpans
{
    public static function html(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $out = '';
        $parts = preg_split('/([\x{0E00}-\x{0E7F}]+|[\x{1000}-\x{109F}\x{AA60}-\x{AA7F}]+|[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        foreach ($parts as $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            if (preg_match('/^[\x{0E00}-\x{0E7F}]+$/u', $part)) {
                $out .= '<span class="th">'.$escaped.'</span>';
            } elseif (preg_match('/^[\x{1000}-\x{109F}\x{AA60}-\x{AA7F}]+$/u', $part)) {
                $out .= '<span class="my">'.$escaped.'</span>';
            } elseif (preg_match('/^[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]+$/u', $part)) {
                $out .= '<span class="zh">'.$escaped.'</span>';
            } else {
                $out .= $escaped;
            }
        }

        return $out;
    }
}
