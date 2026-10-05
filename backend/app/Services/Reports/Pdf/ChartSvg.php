<?php

namespace App\Services\Reports\Pdf;

/**
 * Draws report charts as plain SVG from the stored visualization specification (series are aggregates,
 * never raw rows). Used for the PDF export where no browser renders Recharts. Bars, lines and histograms
 * are supported; tables are rendered as HTML by the template.
 */
class ChartSvg
{
    private const W = 640;

    private const H = 220;

    private const PAD = ['top' => 26, 'right' => 20, 'bottom' => 44, 'left' => 64];

    /** Colour theme (FEAT-VIS-004); ReportPdfBuilder sets it from the analysis. Bars cycle, lines use the first. */
    public static array $palette = ['#087A43'];

    /** Font family handed to dompdf's SVG surface; ReportPdfBuilder sets the absolute Helvetica path. */
    public static string $textFont = 'sans-serif';

    /** @param  array<string, mixed>  $spec  VisualizationResource shape (type, x, y, series) */
    public static function render(array $spec): ?string
    {
        return self::renderWithKey($spec)['svg'] ?? null;
    }

    /**
     * The SVG renderer inside dompdf only has Latin core fonts, so category names in other scripts (Thai,
     * Myanmar, ...) are replaced by numbers in the drawing and returned as a key for the HTML caption.
     *
     * @return array{svg: string, key: list<array{n: int, label: string}>}|null
     */
    public static function renderWithKey(array $spec): ?array
    {
        $type = strtoupper((string) ($spec['type'] ?? ''));
        $x = (string) ($spec['x']['field'] ?? '');
        $y = (string) ($spec['y']['field'] ?? '');
        $series = array_values(array_filter((array) ($spec['series'] ?? []), fn ($row) => is_array($row) && isset($row[$y]) && is_numeric($row[$y])));
        if ($series === [] || $x === '' || $y === '') {
            return null;
        }

        $labels = array_map(function ($row) use ($type, $x) {
            if ($type === 'HISTOGRAM' && isset($row['from'])) {
                return self::compact((float) $row['from']); // bucket lower bound; buckets are contiguous
            }

            return (string) ($row[$x] ?? '');
        }, $series);
        $values = array_map(fn ($row) => (float) $row[$y], $series);

        $labels = self::distinguish($labels);
        $key = [];
        // Numbered bars with a key under the chart when names cannot be drawn (non-Latin) or would be cut off.
        $tooLong = $type !== 'LINE' && max(array_map('mb_strlen', $labels)) > (count($labels) > 8 ? 9 : 16);
        if (! self::latin(implode('', $labels)) || $tooLong) {
            foreach ($labels as $i => $label) {
                $key[] = ['n' => $i + 1, 'label' => $label];
                $labels[$i] = (string) ($i + 1);
            }
        }
        if (! self::latin((string) ($spec['y']['label'] ?? ''))) {
            $spec['y']['label'] = ''; // the HTML caption carries the label
        }

        $svg = match ($type) {
            'LINE' => self::line($labels, $values, $spec),
            'BAR', 'HISTOGRAM' => self::bars($labels, $values, $spec),
            'PIE' => self::pie($labels, $values, $spec),
            default => null,
        };
        if ($svg !== null) {
            // dompdf's SVG renderer passes the family to the PDF engine as "<family>.afm" with no directory, which
            // fails for core names and silently keeps the page's last font (after Thai text: no digits). Giving the
            // absolute path of the engine's Helvetica metrics makes every text node resolve to a font with digits.
            $svg = str_replace('<text ', '<text font-family="'.self::esc(self::$textFont).'" ', $svg);
        }

        return $svg === null ? null : ['svg' => $svg, 'key' => $key];
    }

    /**
     * Long category labels that share a long common prefix (e.g. auction round titles differing only in the lot)
     * are shown by their distinguishing tail, prefixed with an ellipsis. The full value stays in the data.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public static function distinguish(array $labels): array
    {
        if (count($labels) < 2) {
            return $labels;
        }
        $shortest = min(array_map('mb_strlen', $labels));
        if ($shortest <= 24) {
            return $labels;
        }
        $prefix = 0;
        $first = $labels[0];
        for ($i = 0; $i < $shortest; $i++) {
            $ch = mb_substr($first, $i, 1);
            foreach ($labels as $label) {
                if (mb_substr($label, $i, 1) !== $ch) {
                    break 2;
                }
            }
            $prefix = $i + 1;
        }
        // Cut at the last word boundary inside the prefix so words are not split.
        $cut = $prefix;
        while ($cut > 0 && ! preg_match('/[\s(,\/-]/u', mb_substr($first, $cut - 1, 1))) {
            $cut--;
        }
        if ($cut < 12) {
            return $labels;
        }

        return array_map(fn ($l) => '…'.ltrim(mb_substr($l, $cut), ' (,'), $labels);
    }

    /** True when the text only uses characters the PDF core fonts can draw. */
    public static function latin(string $text): bool
    {
        return preg_match('/[^\x{0000}-\x{024F}\x{2000}-\x{206F}\x{20A0}-\x{20CF}]/u', $text) !== 1;
    }

    private static function frame(array $spec, float $min, float $max): array
    {
        $plotW = self::W - self::PAD['left'] - self::PAD['right'];
        $plotH = self::H - self::PAD['top'] - self::PAD['bottom'];
        $lo = min(0.0, $min);
        $hi = max($max, $lo + 1e-9);
        $span = $hi - $lo;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.self::W.'" height="'.self::H.'" viewBox="0 0 '.self::W.' '.self::H.'" font-family="sans-serif">';
        // gridlines + y axis labels (5 ticks)
        for ($i = 0; $i <= 4; $i++) {
            $v = $lo + $span * $i / 4;
            $yy = self::PAD['top'] + $plotH - ($v - $lo) / $span * $plotH;
            $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#E2E8F0" stroke-width="1"/>', self::PAD['left'], $yy, self::W - self::PAD['right'], $yy);
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" fill="#475569" text-anchor="end">%s</text>', self::PAD['left'] - 6, $yy + 3, self::esc(self::tick($v)));
        }
        $unit = (string) ($spec['y']['unit'] ?? '');
        $yLabel = trim((string) ($spec['y']['label'] ?? '').($unit !== '' ? " ({$unit})" : ''));
        $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#64748B">%s</text>', self::PAD['left'], 10, self::esc($yLabel));

        return [$svg, $plotW, $plotH, $lo, $span];
    }

    private static function bars(array $labels, array $values, array $spec): string
    {
        [$svg, $plotW, $plotH, $lo, $span] = self::frame($spec, min($values), max($values));
        $n = count($values);
        $slot = $plotW / $n;
        $barW = max(4, $slot * 0.62);
        foreach ($values as $i => $v) {
            $x0 = self::PAD['left'] + $slot * $i + ($slot - $barW) / 2;
            $y0 = self::PAD['top'] + $plotH - ($v - $lo) / $span * $plotH;
            $zero = self::PAD['top'] + $plotH - (0 - $lo) / $span * $plotH;
            $top = min($y0, $zero);
            $h = max(0.5, abs($zero - $y0));
            $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3" fill="%s"/>', $x0, $top, $barW, $h, self::color($i));
            $svg .= sprintf('<text x="%.1f" y="%.1f" font-size="9" fill="#475569" text-anchor="middle">%s</text>', $x0 + $barW / 2, $top - 4, self::esc(self::num($v)));
            $svg .= self::xLabel($x0 + $barW / 2, (string) $labels[$i], $n);
        }

        return $svg.'</svg>';
    }

    private static function line(array $labels, array $values, array $spec): string
    {
        [$svg, $plotW, $plotH, $lo, $span] = self::frame($spec, min($values), max($values));
        $n = count($values);
        $step = $n > 1 ? $plotW / ($n - 1) : 0;
        $points = [];
        foreach ($values as $i => $v) {
            $px = self::PAD['left'] + $step * $i;
            $py = self::PAD['top'] + $plotH - ($v - $lo) / $span * $plotH;
            $points[] = sprintf('%.1f,%.1f', $px, $py);
            $every = max(1, (int) ceil($n / 12));
            if ($i % $every === 0 || $i === $n - 1) {
                $svg .= self::xLabel($px, (string) $labels[$i], min($n, 12));
            }
        }
        $svg .= sprintf('<polyline fill="none" stroke="%s" stroke-width="2" points="%s"/>', self::color(0), implode(' ', $points));
        [$fx, $fy] = explode(',', $points[0]);
        [$lx, $ly] = explode(',', $points[$n - 1]);
        $svg .= sprintf('<circle cx="%s" cy="%s" r="3.5" fill="%s"/><circle cx="%s" cy="%s" r="3.5" fill="%s"/>', $fx, $fy, self::color(0), $lx, $ly, self::color(0));
        $svg .= sprintf('<text x="%s" y="%.1f" font-size="9" fill="#475569" text-anchor="start">%s</text>', $fx, (float) $fy - 7, self::esc(self::num($values[0])));
        $svg .= sprintf('<text x="%s" y="%.1f" font-size="9" fill="#475569" text-anchor="end">%s</text>', $lx, (float) $ly - 7, self::esc(self::num($values[$n - 1])));

        return $svg.'</svg>';
    }

    /**
     * FEAT-VIS-005 pie: slices in palette order with their share, legend on the right (numbers when the key is in
     * use). Values are the stored group totals; shares are drawn from them, nothing else is computed.
     */
    private static function pie(array $labels, array $values, array $spec): string
    {
        $total = array_sum($values);
        if ($total <= 0) {
            $total = 1.0;
        }
        $cx = 118.0;
        $cy = self::H / 2 + 4;
        $r = 84.0;
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" font-family="%s">', self::W, self::H, self::W, self::H, self::esc(self::$textFont));
        $yLabel = (string) ($spec['y']['label'] ?? '');
        $unit = (string) ($spec['y']['unit'] ?? '');
        $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#64748B">%s</text>', 20, 12, self::esc(trim($yLabel.($unit !== '' ? " ({$unit})" : ''))));
        $start = -M_PI / 2;
        $n = count($values);
        foreach ($values as $i => $v) {
            $share = $v / $total;
            $end = $start + $share * 2 * M_PI;
            if ($n === 1 || $share >= 0.9999) {
                $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s"/>', $cx, $cy, $r, self::color($i));
            } elseif ($share > 0) {
                $x1 = $cx + $r * cos($start);
                $y1 = $cy + $r * sin($start);
                $x2 = $cx + $r * cos($end);
                $y2 = $cy + $r * sin($end);
                $large = $share > 0.5 ? 1 : 0;
                $svg .= sprintf('<path d="M%.1f,%.1f L%.1f,%.1f A%.1f,%.1f 0 %d,1 %.1f,%.1f Z" fill="%s" stroke="#FFFFFF" stroke-width="1.5"/>', $cx, $cy, $x1, $y1, $r, $r, $large, $x2, $y2, self::color($i));
                if ($share >= 0.06) {
                    $mid = ($start + $end) / 2;
                    $svg .= sprintf('<text x="%.1f" y="%.1f" font-size="9" fill="#FFFFFF" text-anchor="middle">%s</text>', $cx + $r * 0.62 * cos($mid), $cy + $r * 0.62 * sin($mid) + 3, self::esc(self::pct($share)));
                }
            }
            $start = $end;
        }
        // Legend: swatch, label, value and share; up to 8 rows fit the frame.
        $lx = 240;
        $rowH = min(22, (self::H - 40) / max(1, $n));
        foreach ($values as $i => $v) {
            $ly = 36 + $rowH * $i;
            $label = (string) $labels[$i];
            $label = mb_strlen($label) > 28 ? mb_substr($label, 0, 27).'…' : $label;
            $svg .= sprintf('<rect x="%d" y="%.1f" width="10" height="10" rx="2" fill="%s"/>', $lx, $ly - 9, self::color($i));
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" fill="#334155">%s</text>', $lx + 16, $ly, self::esc($label));
            $svg .= sprintf('<text x="%d" y="%.1f" font-size="10" fill="#475569" text-anchor="end">%s · %s</text>', self::W - 24, $ly, self::esc(self::num($v)), self::esc(self::pct($v / $total)));
        }

        return $svg.'</svg>';
    }

    private static function pct(float $share): string
    {
        $p = $share * 100;

        return ($p >= 10 || $p === 0.0 ? number_format($p, 0) : number_format($p, 1)).'%';
    }

    /** Palette colour for the i-th category (bars) or the series colour (i = 0). */
    public static function color(int $i): string
    {
        $palette = self::$palette !== [] ? array_values(self::$palette) : ['#087A43'];

        return $palette[$i % count($palette)];
    }

    private static function xLabel(float $cx, string $label, int $n): string
    {
        $max = $n > 8 ? 9 : 16;
        $text = mb_strlen($label) > $max ? mb_substr($label, 0, $max - 1).'…' : $label;
        $y = self::H - self::PAD['bottom'] + 14;

        return sprintf('<text x="%.1f" y="%d" font-size="9" fill="#475569" text-anchor="middle">%s</text>', $cx, $y, self::esc($text));
    }

    public static function num(float $v): string
    {
        $a = abs($v);
        if ($a >= 1_000_000_000) {
            return sprintf('%.1fB', $v / 1_000_000_000);
        }
        if ($a >= 1_000_000) {
            return sprintf('%.1fM', $v / 1_000_000);
        }
        if ($a >= 10_000) {
            return number_format($v, 0);
        }
        if ($a >= 100) {
            return number_format($v, 1);
        }
        if (floor($v) == $v) {
            return (string) (int) $v;
        }

        return number_format($v, 2);
    }

    /** Axis tick: no decimals above 100, thousands separators, M/B above a million. */
    public static function tick(float $v): string
    {
        $a = abs($v);
        if ($a >= 1_000_000) {
            return self::num($v);
        }
        if ($a >= 100) {
            return number_format($v, 0);
        }

        return self::num($v);
    }

    /** Short category label for histogram buckets: 120, 9.0k, 1.2M. */
    public static function compact(float $v): string
    {
        $a = abs($v);
        if ($a >= 1_000_000) {
            return self::num($v);
        }
        if ($a >= 1_000) {
            return sprintf('%.1fk', $v / 1_000);
        }

        return floor($v) == $v ? (string) (int) $v : self::num($v);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
