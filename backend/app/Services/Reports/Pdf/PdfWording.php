<?php

namespace App\Services\Reports\Pdf;

use Illuminate\Support\Facades\Lang;

/** Business wording for the PDF in the report language (lang/{locale}/notes.php, metrics.php). */
class PdfWording
{
    public static function dataNote(string $label, string $metric, ?string $value, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $key = Lang::has('notes.'.$metric, $locale) ? 'notes.'.$metric : 'notes.default';

        return __($key, ['label' => $label, 'value' => $value ?? '', 'metric' => self::metric($metric, $locale)], $locale);
    }

    public static function metric(string $metric, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return Lang::has('metrics.names.'.$metric, $locale) ? __('metrics.names.'.$metric, [], $locale) : str_replace('_', ' ', $metric);
    }
}
