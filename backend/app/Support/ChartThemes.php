<?php

namespace App\Support;

/**
 * FEAT-VIS-004 Chart colour themes. Chosen at chart review, stored on the analysis and applied to every chart in
 * the web report and the PDF. The frontend keeps an identical list (src/utils/chartThemes.ts); colours only,
 * never data. Bars cycle through the palette, lines and histograms use the first colour.
 */
final class ChartThemes
{
    public const DEFAULT = 'emerald';

    /** @var array<string, array{label: string, colors: list<string>}> */
    public const ALL = [
        'emerald' => ['label' => 'Data Speak green', 'colors' => ['#087A43', '#15965A', '#2DB37A', '#5CCB9A', '#8FE0BD', '#065F46']],
        'ocean' => ['label' => 'Ocean blue', 'colors' => ['#1D4ED8', '#2563EB', '#3B82F6', '#60A5FA', '#93C5FD', '#1E3A8A']],
        'sunset' => ['label' => 'Sunset orange', 'colors' => ['#C2410C', '#EA580C', '#F97316', '#FB923C', '#FDBA74', '#9A3412']],
        'plum' => ['label' => 'Plum violet', 'colors' => ['#6D28D9', '#7C3AED', '#8B5CF6', '#A78BFA', '#C4B5FD', '#4C1D95']],
        'slate' => ['label' => 'Monochrome', 'colors' => ['#0F172A', '#334155', '#475569', '#64748B', '#94A3B8', '#1E293B']],
        'vivid' => ['label' => 'Vivid mix', 'colors' => ['#087A43', '#1D4ED8', '#EA580C', '#7C3AED', '#DB2777', '#0891B2']],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function normalize(?string $key): string
    {
        $key = strtolower(trim((string) $key));

        return isset(self::ALL[$key]) ? $key : self::DEFAULT;
    }

    /** @return list<string> */
    public static function colors(?string $key): array
    {
        return self::ALL[self::normalize($key)]['colors'];
    }
}
