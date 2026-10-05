<?php

namespace App\Http\Resources;

use App\Services\Ai\PlaceholderResolver;
use App\Services\Reporting\NarrativeRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TRC-API-001: claim with rendered text, placeholder form, validation state and reasons, supporting
 * evidence (metric, value, method, source columns) and generation metadata. @mixin \App\Models\Claim
 */
class ClaimResource extends JsonResource
{
    /** @param  string|null  $displayLocale  render the stored translation for this language when the claim has one */
    public function __construct($resource, private readonly ?PlaceholderResolver $resolver = null, private readonly ?string $displayLocale = null)
    {
        parent::__construct($resource);
    }

    /** The template to render: a verified translation for the display language, else the original. */
    public function template(): string
    {
        $translations = (array) ($this->statement_translations ?? []);

        return $this->displayLocale !== null && isset($translations[$this->displayLocale]) ? (string) $translations[$this->displayLocale] : (string) $this->statement;
    }

    public function isTranslated(): bool
    {
        return $this->displayLocale !== null && isset(((array) ($this->statement_translations ?? []))[$this->displayLocale]);
    }

    public function toArray(Request $request): array
    {
        $resolver = $this->resolver ?? PlaceholderResolver::for($this->analysis);
        $renderer = new NarrativeRenderer;
        $generation = $this->relationLoaded('aiGeneration') ? $this->aiGeneration : $this->aiGeneration()->first();

        return [
            'id' => $this->public_id,
            'kind' => $this->claim_kind->value,
            'statement' => $renderer->render($this->template(), $resolver),
            'statement_template' => $this->template(),
            'original_statement_template' => $this->statement,
            'language' => $this->isTranslated() ? $this->displayLocale : null, // null: the report's own language
            'claimed_value' => $this->claimed_value,
            'computed_value' => $this->computed_value,
            'status' => $this->status->value,
            'validation' => [
                'status' => $this->status->value,
                'method' => $this->validation_method,
                'reasons' => $this->validation_notes ? array_map('trim', explode('|', $this->validation_notes)) : [],
            ],
            'in_report' => $this->in_report,
            'position' => $this->position,
            'evidence' => $this->whenLoaded('evidence', fn () => $this->evidence->map(function ($e) use ($resolver, $request) {
                $view = $resolver->viewFor($e->evidence_key);

                return array_merge((new EvidenceResource($e))->toArray($request), [
                    'label' => $view['label'] ?? null,
                    'dimension' => $view['dimension'] ?? null,
                    'group' => $view['group'] ?? null,
                    'period_from' => $view['period_from'] ?? null,
                    'period_to' => $view['period_to'] ?? null,
                ]);
            })->values()->all()),
            'primary_evidence_id' => $this->whenLoaded('evidence', fn () => $this->evidence->firstWhere('pivot.is_primary', true)?->evidence_key ?? $this->evidence->first()?->evidence_key),
            'generation' => $generation ? [
                'id' => (string) $generation->id,
                'model' => $generation->model,
                'prompt_version' => $generation->prompt_version,
                'status' => $generation->status->value,
            ] : null,
        ];
    }
}
