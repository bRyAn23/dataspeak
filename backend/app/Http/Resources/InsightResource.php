<?php

namespace App\Http\Resources;

use App\Services\Ai\EvidenceSentences;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Reporting\NarrativeRenderer;
use App\Services\Reports\DisplayLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** INS-API-001 item. @mixin \App\Models\Insight */
class InsightResource extends JsonResource
{
    public function __construct($resource, private readonly ?PlaceholderResolver $resolver = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $display = DisplayLabels::for($this->analysis, $locale); // FEAT-I18N-002/003
        $headline = $display->text($this->headline);
        $description = $display->text($this->description);
        // The analytics service writes insight text in English; another language uses the software sentence
        // for the same evidence (lang/{locale}/sentences.php), rendered from the stored values.
        if ($locale !== 'en' && $this->resolver !== null && $this->evidence !== null) {
            $sentence = EvidenceSentences::for($this->evidence, $this->resolver, $locale);
            if ($sentence !== null) {
                $headline = (new NarrativeRenderer)->render($sentence, $this->resolver);
                $description = null;
            }
        }

        return [
            'rank' => $this->rank,
            'insight_type' => $this->insight_type->value,
            'headline' => $headline,
            'description' => $description,
            'score' => (float) $this->score,
            'is_selected' => $this->is_selected,
            'evidence_id' => $this->evidence?->evidence_key,
            'evidence_ids' => $this->supporting_evidence_keys,
            'evidence' => new EvidenceResource($this->whenLoaded('evidence')),
        ];
    }
}
