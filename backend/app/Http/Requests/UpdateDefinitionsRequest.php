<?php

namespace App\Http\Requests;

use App\Enums\Aggregation;
use App\Enums\SemanticType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** DEF-API-001 (Laravel API doc Section 8). Enum values come from the data dictionary. */
class UpdateDefinitionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'definitions' => ['required', 'array', 'min:1'],
            'definitions.*.column' => ['required', 'string', 'max:255', 'distinct'],
            // The report is English only (FEAT-I18N-002): names in other scripts are refused; the Define step suggests English ones.
            'definitions.*.business_name' => ['required', 'string', 'max:255', 'regex:/^[\x{0020}-\x{024F}\x{2000}-\x{206F}\x{20A0}-\x{20CF}]+$/u'],
            'definitions.*.description' => ['nullable', 'string', 'max:500'],
            'definitions.*.semantic_type' => ['required', 'string', Rule::in(SemanticType::values())],
            'definitions.*.unit' => ['nullable', 'string', 'max:32'],
            'definitions.*.aggregation' => ['nullable', 'string', Rule::in(Aggregation::values())],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('definitions', []) as $i => $definition) {
                    if (($definition['semantic_type'] ?? null) === SemanticType::CURRENCY->value && blank($definition['unit'] ?? null)) {
                        $validator->errors()->add("definitions.{$i}.unit", 'A currency column requires a unit (for example THB).');
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'definitions.*.business_name.regex' => 'Business names must be in English because the report is written in English.',
        ];
    }
}
