<?php

namespace App\Support;

use App\Models\Analysis;
use Illuminate\Http\Request;

/**
 * Looks up an analysis by public id scoped to the authenticated user. Other users' analyses are
 * indistinguishable from non-existent ones (404) to avoid id enumeration; documented choice.
 */
trait ResolvesOwnedAnalysis
{
    protected function ownedAnalysis(Request $request, string $publicId): Analysis
    {
        return Analysis::query()->ownedBy($request->user())->where('public_id', $publicId)->firstOrFail();
    }
}
