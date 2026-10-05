<?php

namespace App\Services\Reports\Pdf;

use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Support\ChartThemes;
use App\Support\Locale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * FEAT-RPT-003 Executive report as PDF: cover (logo, analysis name, created timestamp, user),
 * letterhead with the Data Speak logo on every page, footer with generation timestamp and page numbers.
 * Content is the same data the web report shows (ReportResource); charts are drawn as SVG from the
 * stored specifications. Nothing is computed here.
 */
class ReportPdfBuilder
{
    public function build(Report $report, Request $request): string
    {
        $report->loadMissing('analysis.user');
        // FEAT-I18N-003: the PDF follows the interface language (SetLocale middleware), exactly like the web report:
        // headings, labels and chart titles in that language, each statement in its stored translation when there
        // is one and in the report's own language otherwise. Nothing is re-interpreted here.
        $locale = Locale::normalize(app()->getLocale());

        return $this->render($report, $request, $locale);
    }

    private function render(Report $report, Request $request, string $locale): string
    {
        // The CJK font is large (17 MB, 65k glyphs): only Chinese reports load it, with more memory and font subsetting
        // so the PDF embeds just the glyphs it uses.
        if ($locale === 'zh') {
            ini_set('memory_limit', '768M');
        }
        ChartSvg::$textFont = base_path('vendor/dompdf/dompdf/lib/fonts/Helvetica'); // see ChartSvg::$textFont
        ChartSvg::$palette = ChartThemes::colors($report->analysis->chart_theme); // FEAT-VIS-004 theme chosen at chart review
        $data = (new ReportResource($report))->toArray($request);
        $analysis = $report->analysis;
        $generatedAt = now();

        $charts = array_map(function (array $v) {
            if (ChartHtml::wantsHtml($v)) { // long or non-Latin category labels: typeset as HTML bars
                $v['html'] = ChartHtml::bars($v);
                $v['svg'] = null;
                $v['key'] = [];
                $v['summary'] = $v['summary'] ?? [];

                return $v;
            }
            $drawn = ChartSvg::renderWithKey($v);
            // dompdf renders SVG only as an image, not as inline markup.
            $v['svg'] = $drawn === null ? null : 'data:image/svg+xml;base64,'.base64_encode($drawn['svg']);
            $v['key'] = $drawn['key'] ?? [];
            $v['summary'] = $v['summary'] ?? [];

            return $v;
        }, $data['visualizations'] instanceof Collection ? $data['visualizations']->all() : (array) $data['visualizations']);

        $summaryClaims = collect($data['claims'])->filter(fn ($c) => ($c instanceof JsonResource ? $c->toArray($request) : $c)['kind'] === 'EXECUTIVE_SUMMARY')
            ->map(fn ($c) => $c instanceof JsonResource ? $c->toArray($request) : $c)->values()->all();
        $allClaims = collect($data['claims'])->map(fn ($c) => $c instanceof JsonResource ? $c->toArray($request) : $c)->values()->all();
        $chartClaimIds = collect($charts)->flatMap(fn ($c) => array_column($c['summary'], 'claim_id'))->filter()->all();
        $otherFindings = array_values(array_filter($allClaims, fn ($c) => $c['kind'] === 'FINDING' && ! in_array($c['id'], $chartClaimIds, true)));
        $caveats = array_values(array_filter($allClaims, fn ($c) => $c['kind'] === 'CAVEAT'));
        $insights = collect($data['insights'])->map(fn ($i) => $i instanceof JsonResource ? $i->toArray($request) : $i)->values()->all();

        $data['summary_metrics'] = array_map(function (array $m) {
            $m['display_value'] = preg_replace('/^(-?[\d,]+)\.0(?=\s|$)/u', '$1', (string) $m['display_value']) ?? $m['display_value'];

            return $m;
        }, (array) ($data['summary_metrics'] ?? []));

        $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('pdf/logo.png')));
        $fontDir = resource_path('pdf/fonts');

        $pdf = Pdf::setOptions([
            'fontDir' => storage_path('fonts'),
            'fontCache' => storage_path('fonts'),
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'Noto Sans',
            'chroot' => [resource_path('pdf')],
            'dpi' => 96,
        ])->loadView('pdf.report', [
            'report' => $data,
            'analysis' => $analysis,
            'user' => $analysis->user,
            'generatedAt' => $generatedAt,
            'logo' => $logo,
            'fontDir' => $fontDir,
            'charts' => $charts,
            'locale' => $locale,
            'summaryClaims' => $summaryClaims,
            'otherFindings' => $otherFindings,
            'caveats' => $caveats,
            'insights' => $insights,
        ])->setPaper('a4', 'portrait');

        $pdf->render();
        $dom = $pdf->getDomPDF();
        $canvas = $dom->getCanvas();
        $font = $dom->getFontMetrics()->getFont('Noto Sans', 'normal') ?? $dom->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        // Footer text is drawn by dompdf's canvas, one font per call, and the script fonts have no Latin glyphs:
        // the localised word is drawn with the language's font, the timestamp and page numbers with Noto Sans.
        $scriptFamily = match ($locale) {
            'th' => 'Noto Sans Thai', 'zh' => 'Noto Sans SC', 'my' => 'Noto Sans Myanmar', default => 'Noto Sans',
        };
        $scriptFont = $dom->getFontMetrics()->getFont($scriptFamily, 'normal') ?? $font;
        $grey = [0.42, 0.45, 0.5];
        $y = $canvas->get_height() - 28;
        $word = __('report.generated_word', [], $locale);
        $canvas->page_text(40, $y, $word, $scriptFont, 8, $grey);
        $wordWidth = $dom->getFontMetrics()->getTextWidth($word, $scriptFont, 8);
        $canvas->page_text(40 + $wordWidth + 4, $y, $generatedAt->format('j M Y H:i').' UTC · Data Speak', $font, 8, $grey);
        $canvas->page_text($canvas->get_width() - 110, $y, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, $grey);

        return $pdf->output();
    }

    public static function fileName(Report $report): string
    {
        $slug = preg_replace('/[^A-Za-z0-9\x{0E00}-\x{0E7F}\x{1000}-\x{109F}]+/u', '-', (string) $report->analysis->name) ?: 'report';

        return 'DataSpeak-'.trim($slug, '-').'-'.now()->format('Ymd-Hi').'.pdf';
    }
}
