<?php

namespace App\Services\Reports;

use App\Models\Analysis;
use App\Support\Locale;

/**
 * FEAT-I18N-002 English display labels. The report is English only, so every header or category value written
 * in another script is shown through this map (built by DisplayLabelTranslator after the run and stored on the
 * analysis). Stored evidence, insights and chart specifications keep the original values; only presentation
 * (API resources, placeholder views, the AI payload, the PDF) goes through here.
 */
final class DisplayLabels
{
    /** @var array<string, self> */
    private static array $cache = [];

    /** @var list<string> regex patterns for the originals, longest first, whitespace runs matched loosely */
    private array $patterns = [];

    /** @var list<string> replacements aligned with */
    private array $replacements = [];

    /** @var list<string> originals, longest first */
    private array $ordered = [];

    /** @param  array<string, string>  $map  original => English */
    private function __construct(private readonly array $map)
    {
        $keys = array_keys($map);
        usort($keys, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $this->ordered = $keys;
        foreach ($keys as $key) {
            // Insight text produced by the analytics service may normalise whitespace; match any whitespace run.
            $parts = array_map(fn ($p) => preg_quote($p, '/'), preg_split('/\s+/u', trim((string) $key)) ?: []);
            $this->patterns[] = '/'.implode('\s+', $parts).'/u';
            $this->replacements[] = $map[$key];
        }
    }

    public static function for(Analysis $analysis, ?string $locale = null): self
    {
        $locale = Locale::normalize($locale ?? app()->getLocale());
        $map = self::mapFor((array) ($analysis->label_translations ?? []), $locale);
        $key = $analysis->id.':'.$locale.':'.md5((string) json_encode($map));

        return self::$cache[$key] ??= new self($map);
    }

    /** Per-locale maps; a legacy root-level {map} is the English one. @return array<string, string> */
    public static function mapFor(array $stored, string $locale): array
    {
        if (isset($stored['map'])) {
            $stored = ['en' => $stored];
        }

        $map = (array) ($stored[$locale]['map'] ?? []);
        if ($locale !== 'en') {
            // A label the model could not render in this language falls back to its English business name,
            // never to the raw column header or category value.
            $map += (array) ($stored['en']['map'] ?? []);
        }

        return $map;
    }

    /** @return array<string, array<string, mixed>> locale => {map, ...} */
    public static function normalizeStored(?array $stored): array
    {
        $stored = (array) $stored;

        return isset($stored['map']) ? ['en' => $stored] : $stored;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /** @return array<string, string> */
    public function map(): array
    {
        return $this->map;
    }

    /** Exact lookup for a single label or category value. */
    public function label(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->map[$value] ?? $value;
    }

    /** Replaces every mapped original inside free text (insight headlines, chart titles), longest first. */
    public function text(?string $text): ?string
    {
        if ($text === null || $text === '' || $this->patterns === []) {
            return $text;
        }

        // Stored insight headlines are capped at 255 characters, so a long original can be cut off at the end of
        // the text. A tail that is a prefix (12+ characters) of a mapped original is replaced by its English first,
        // before shorter originals inside that tail could match.
        if (! self::isLatin($text)) {
            foreach ($this->ordered as $original) {
                $max = min(mb_strlen($original) - 1, mb_strlen($text));
                for ($k = $max; $k >= 12; $k--) {
                    if (mb_substr($text, -$k) === mb_substr($original, 0, $k)) {
                        $text = mb_substr($text, 0, mb_strlen($text) - $k).$this->map[$original];
                        break 2;
                    }
                }
            }
        }

        return preg_replace($this->patterns, $this->replacements, $text) ?? $text;
    }

    /** Maps string cells of a chart series row (category values); numbers and nulls pass through. */
    public function row(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v)) {
                $row[$k] = $this->label($v);
            }
        }

        return $row;
    }

    public static function isLatin(string $text): bool
    {
        return preg_match('/[^\x{0020}-\x{024F}\x{2000}-\x{206F}\x{20A0}-\x{20CF}\x{2190}-\x{21FF}\x{2200}-\x{22FF}\s]/u', $text) !== 1;
    }
}
