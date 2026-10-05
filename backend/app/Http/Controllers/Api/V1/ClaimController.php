<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Services\Ai\PlaceholderResolver;
use App\Support\Locale;
use App\Support\ResolvesOwnedAnalysis;
use Illuminate\Http\Request;

/** MOD-12 Traceability: TRC-API-001 GET /analyses/{id}/claims/{claimId} */
class ClaimController extends Controller
{
    use ResolvesOwnedAnalysis;

    public function show(Request $request, string $analysis, string $claim): ClaimResource
    {
        $model = $this->ownedAnalysis($request, $analysis);
        $this->authorize('view', $model);

        $found = $model->claims()
            ->where('public_id', $claim)
            ->with(['evidence.metricColumn', 'evidence.dimensionColumn', 'aiGeneration'])
            ->firstOrFail();

        // FEAT-I18N-003: a verified translation for the interface language is shown when it exists.
        $display = Locale::normalize(app()->getLocale());
        $translated = isset(((array) ($found->statement_translations ?? []))[$display]);
        $reportLocale = Locale::normalize($model->report?->language ?? 'en');

        return new ClaimResource($found, PlaceholderResolver::for($model, $translated ? $display : $reportLocale), $translated ? $display : null);
    }
}
