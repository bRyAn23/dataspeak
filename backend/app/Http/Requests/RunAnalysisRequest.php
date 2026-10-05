<?php

namespace App\Http\Requests;

use App\Support\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RUN-API-001. Options are restricted to approved keys; nothing else is forwarded. */
class RunAnalysisRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'string', Rule::in(Locale::SUPPORTED)],
            'options' => ['sometimes', 'array'],
            'options.max_insights' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ];
    }
}
