<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Report;
use App\Services\Reports\ReportAssembler;
use Tests\Support\AiScenario;
use Tests\Support\ApiTestCase;

/** RPT-API-003 / FEAT-RPT-003 */
class ReportPdfTest extends ApiTestCase
{
    public function test_pdf_is_generated_with_cover_letterhead_charts_and_page_numbers(): void
    {
        $analysis = AiScenario::build($this->actingAsUser());
        $analysis->forceFill(['status' => AnalysisStatus::COMPLETED, 'source_file_name' => 'sales.csv'])->save();
        app(ReportAssembler::class)->assemble($analysis, null, 'unavailable');

        $response = $this->get($this->urlFor($analysis, '/report/pdf'), ['Accept' => 'application/json']);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename="DataSpeak-Retail-2025-', $response->headers->get('Content-Disposition'));
        $pdf = $response->getContent();
        if (($dump = getenv('PDF_DUMP')) !== false && $dump !== '') {
            file_put_contents($dump, $pdf); // for eyeballing the layout; never set in CI
        }
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), 'cover plus at least one content page');
        $this->assertGreaterThan(50_000, strlen($pdf)); // embedded logo and fonts
        $this->assertDatabaseHas('reports', ['analysis_id' => $analysis->id]);
    }

    public function test_pdf_requires_a_completed_report(): void
    {
        $analysis = AiScenario::build($this->actingAsUser());
        $this->get($this->urlFor($analysis, '/report/pdf'), ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('error.code', 'STEP_NOT_READY');
        $this->assertSame(0, Report::query()->count());
    }

    public function test_other_users_cannot_download(): void
    {
        $analysis = AiScenario::build();
        $analysis->forceFill(['status' => AnalysisStatus::COMPLETED])->save();
        $this->actingAsUser();
        $this->get($this->urlFor($analysis, '/report/pdf'), ['Accept' => 'application/json'])->assertStatus(404);
    }
}
