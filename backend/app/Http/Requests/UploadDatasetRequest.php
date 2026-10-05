<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * DATA-API-001. File-level controls only (Laravel API doc Section 7): extension, MIME, size, non-empty.
 * Structural validation happens in the analytics service. Nothing here reads cell values.
 */
class UploadDatasetRequest extends FormRequest
{
    public function rules(): array
    {
        $maxKb = (int) config('dataspeak.upload.max_mb', 50) * 1024;

        return [
            'file' => [
                'required',
                'file',
                'extensions:'.implode(',', config('dataspeak.upload.allowed_extensions', ['csv'])),
                'mimetypes:'.implode(',', config('dataspeak.upload.allowed_mimetypes', ['text/csv', 'text/plain'])),
                'max:'.$maxKb,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && (int) $value->getSize() === 0) {
                        $fail('The file is empty.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A CSV or Excel (.xlsx) file is required.',
            'file.mimetypes' => 'Only CSV or Excel (.xlsx) files are supported.',
            'file.extensions' => 'Only CSV or Excel (.xlsx) files are supported.',
            'file.max' => 'The file exceeds the maximum size of '.config('dataspeak.upload.max_mb', 50).' MB.',
        ];
    }
}
