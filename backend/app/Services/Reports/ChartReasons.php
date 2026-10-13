<?php

namespace App\Services\Reports;

use App\Support\Locale;

/**
 * FEAT-VIS-006 Why a chart is recommended or offered, in the interface language. The reason follows from the
 * column definitions made at profiling (what the measure means and whether it accumulates) and the chart type;
 * it is written by software from lang/{locale}/charts.php, never by the model, and carries no numbers.
 */
class ChartReasons
{
    /** @param  array<string, mixed>  $spec  {type, x{field,label}, y{field,label}} with labels already localized */
    public static function for(array $spec, string $locale): ?string
    {
        $locale = Locale::normalize($locale);
        $type = strtoupper((string) ($spec['type'] ?? ''));
        $yField = (string) ($spec['y']['field'] ?? '');
        $xField = (string) ($spec['x']['field'] ?? '');
        $key = match (true) {
            $type === 'LINE' => 'line',
            $type === 'PIE' => 'pie',
            $type === 'HISTOGRAM' => 'histogram',
            $type === 'TABLE' => 'table',
            $type === 'BAR' && $xField === 'column' => 'missing',
            $type === 'BAR' && $yField === 'mean' => 'bar_mean',
            $type === 'BAR' => 'bar_sum',
            default => null,
        };

        return $key === null ? null : __('charts.reasons.'.$key, ['y' => (string) ($spec['y']['label'] ?? ''), 'x' => (string) ($spec['x']['label'] ?? '')], $locale);
    }
}
