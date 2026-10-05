<?php

namespace App\Services\Reports\Pdf;

/**
 * Bar charts whose category labels are long or in a non-Latin script are drawn as horizontal HTML bars: dompdf
 * typesets HTML text with the right font for each script, so labels keep their full wording with no numbered key.
 * Values come straight from the stored series; nothing is computed beyond the bar width.
 */
class ChartHtml
{
    public static function wantsHtml(array $spec): bool
    {
        if (strtoupper((string) ($spec['type'] ?? '')) !== 'BAR') {
            return false;
        }
        $x = (string) ($spec['x']['field'] ?? '');
        $labels = array_map(fn ($row) => (string) ($row[$x] ?? ''), (array) ($spec['series'] ?? []));
        if ($labels === []) {
            return false;
        }
        $longest = max(array_map('mb_strlen', $labels));

        return ! ChartSvg::latin(implode('', $labels)) || $longest > 16;
    }

    public static function bars(array $spec): string
    {
        $x = (string) ($spec['x']['field'] ?? '');
        $y = (string) ($spec['y']['field'] ?? '');
        $rows = array_values(array_filter((array) ($spec['series'] ?? []), fn ($r) => is_array($r) && isset($r[$y]) && is_numeric($r[$y])));
        if ($rows === []) {
            return '';
        }
        $max = max(array_map(fn ($r) => abs((float) $r[$y]), $rows)) ?: 1.0;
        $html = '<table class="hbars">';
        foreach ($rows as $i => $r) {
            $value = (float) $r[$y];
            $pct = max(1, (int) round(abs($value) / $max * 100));
            $html .= '<tr>'
                .'<td class="hl">'.ScriptSpans::html((string) ($r[$x] ?? '')).'</td>'
                .'<td class="hb"><div class="bar" style="width:'.$pct.'%;background:'.ChartSvg::color($i).'"></div></td>'
                .'<td class="hv">'.htmlspecialchars(ChartSvg::num($value), ENT_QUOTES, 'UTF-8').'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }
}
