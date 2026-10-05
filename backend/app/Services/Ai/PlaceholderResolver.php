<?php

namespace App\Services\Ai;

use App\Models\Analysis;
use App\Models\Evidence;
use App\Services\Reports\DisplayLabels;
use App\Support\Locale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * Builds the per-evidence "view" that placeholders may reference, from stored evidence and the
 * user's field definitions. The whitelist is config('dataspeak.ai.placeholder_fields').
 * The same resolver feeds the prompt payload, the validator and the renderer, so they never disagree.
 */
class PlaceholderResolver
{
    /** @var array<string, array<string, string|null>> evidence_key => field => value */
    private array $views = [];

    /** @var array<string, Evidence> */
    private array $evidence = [];

    private string $locale = 'en';

    public function locale(): string
    {
        return $this->locale;
    }

    public static function for(Analysis $analysis, ?string $locale = null): self
    {
        $locale = Locale::normalize($locale ?? app()->getLocale());
        $analysis->loadMissing(['columns.definition', 'evidence']);
        $display = DisplayLabels::for($analysis, $locale); // FEAT-I18N-002/003: labels in the report language
        $labels = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $display->label($c->definition?->business_name ?: $c->source_name)])->all();
        $units = $analysis->columns->mapWithKeys(fn ($c) => [$c->source_name => $c->definition?->unit])->all();

        $self = new self;
        $self->locale = $locale;
        foreach ($analysis->evidence as $e) {
            $self->evidence[$e->evidence_key] = $e;
            $self->views[$e->evidence_key] = self::view($e, $labels, $units, $display, $locale);
        }

        return $self;
    }

    /** @return array<string, string|null> */
    private static function view(Evidence $e, array $labels, array $units, DisplayLabels $display, string $locale = 'en'): array
    {
        $cols = $e->source_columns ?? [];
        $metricCol = $e->metricColumn?->source_name ?? ($cols[count($cols) - 1] ?? null);
        $dimCol = $e->dimensionColumn?->source_name ?? (count($cols) > 1 ? $cols[0] : null);
        $payload = $e->payload ?? [];
        $params = $e->parameters ?? [];

        return [
            'display_value' => $e->display_value,
            'label' => $metricCol !== null ? ($labels[$metricCol] ?? $metricCol) : 'dataset',
            'unit' => $metricCol !== null ? ($units[$metricCol] ?? ($payload['unit'] ?? null)) : null,
            'dimension' => $dimCol !== null && $dimCol !== $metricCol ? ($labels[$dimCol] ?? $dimCol) : null,
            'group' => $display->label(isset($payload['top_group']) ? (string) $payload['top_group'] : (isset($payload['highest_group']) ? (string) $payload['highest_group'] : null)),
            'period' => isset($params['period']) ? (Lang::has('charts.periods.'.$params['period'], $locale) ? __('charts.periods.'.$params['period'], [], $locale) : (string) $params['period']) : null,
            'period_from' => isset($params['from']) ? (string) $params['from'] : (isset($payload['first_period']) ? (string) $payload['first_period'] : null),
            'period_to' => isset($params['to']) ? (string) $params['to'] : (isset($payload['last_period']) ? (string) $payload['last_period'] : null),
        ];
    }

    public function has(string $evidenceKey): bool
    {
        return isset($this->views[$evidenceKey]);
    }

    public function evidence(string $evidenceKey): ?Evidence
    {
        return $this->evidence[$evidenceKey] ?? null;
    }

    /** Null when the field is not whitelisted or has no value for this evidence. */
    public function resolve(string $evidenceKey, string $field): ?string
    {
        if (! in_array($field, (array) config('dataspeak.ai.placeholder_fields'), true)) {
            return null;
        }
        $value = $this->views[$evidenceKey][$field] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return array<string, string|null> */
    public function viewFor(string $evidenceKey): array
    {
        return $this->views[$evidenceKey] ?? [];
    }

    /** @return Collection<string, Evidence> */
    public function all(): Collection
    {
        return collect($this->evidence);
    }
}
