<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** ANL-API-002 */
class StoreAnalysisRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
