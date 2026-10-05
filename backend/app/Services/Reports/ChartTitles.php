<?php

namespace App\Services\Reports;

use App\Support\Locale;
use Illuminate\Support\Facades\Lang;

/**
 * The analytics service composes chart titles in English from a handful of patterns. For a report in another
 * language the pattern is recognised and re-rendered from lang/{locale}/charts.php; labels inside are then
 * translated by DisplayLabels. Unknown titles are left as they are (never invented).
 */
class ChartTitles
{
    public static function localize(?string $title, string $locale): ?string
    {
        $locale = Locale::normalize($locale);
        if ($title === null || $title === '' || $locale === 'en') {
            return $title;
        }
        $period = fn (string $p) => Lang::has('charts.periods.'.$p, $locale) ? __('charts.periods.'.$p, [], $locale) : $p;

        if (preg_match('/^(.+) by (month|week|day|quarter|year|period)$/u', $title, $m)) {
            return __('charts.by_period', ['y' => $m[1], 'period' => $period($m[2])], $locale);
        }
        if (preg_match('/^Total (.+) by (.+)$/u', $title, $m)) {
            return __('charts.total_by', ['y' => $m[1], 'x' => $m[2]], $locale);
        }
        if (preg_match('/^Average (.+) by (.+)$/u', $title, $m)) {
            return __('charts.average_by', ['y' => $m[1], 'x' => $m[2]], $locale);
        }
        if (preg_match('/^Share of (.+) by (.+)$/u', $title, $m)) {
            return __('charts.share_by', ['y' => $m[1], 'x' => $m[2]], $locale);
        }
        if (preg_match('/^Distribution of (.+)$/u', $title, $m)) {
            return __('charts.distribution', ['y' => $m[1]], $locale);
        }
        if (preg_match('/^Association between (.+) and (.+)$/u', $title, $m)) {
            return __('charts.association', ['a' => $m[1], 'b' => $m[2]], $locale);
        }

        return $title;
    }
}
