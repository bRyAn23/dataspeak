<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** AUTH-API-002 */
class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
