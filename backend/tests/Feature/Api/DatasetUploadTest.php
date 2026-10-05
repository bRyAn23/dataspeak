<?php

namespace Tests\Feature\Api;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Evidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ApiTestCase;

/** DATA-API-001, FEAT-UPL-001/003, FEAT-SEC-002 */
class DatasetUploadTest extends ApiTestCase
{
    public function test_csv_upload_is_stored_transiently_and_moves_to_uploaded(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->create();

        $response = $this->post($this->urlFor($analysis, '/dataset'), ['file' => $this->csvFile()], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'UPLOADED')
            ->assertJsonPath('data.dataset.file_name', 'sales.csv')
            ->assertJsonPath('data.dataset.transient_file_present', true);
        $this->assertSame(64, strlen($response->json('data.dataset.sha256')));
        Storage::disk('transient')->assertExists('analyses/'.$analysis->public_id.'/dataset.csv');
        $this->assertDatabaseHas('analysis_stages', ['analysis_id' => $analysis->id, 'stage' => 'UPLOAD', 'status' => 'SUCCEEDED']);
    }

    public function test_non_csv_empty_and_oversized_files_are_rejected(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->create();
        $url = $this->urlFor($analysis, '/dataset');

        $this->post($url, ['file' => UploadedFile::fake()->create('data.xls', 10, 'application/vnd.ms-excel')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->post($url, ['file' => UploadedFile::fake()->create('data.json', 10, 'application/json')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('empty.csv', '')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        config(['dataspeak.upload.max_mb' => 1]);
        $this->post($url, ['file' => UploadedFile::fake()->create('big.csv', 2048, 'text/csv')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post($url, [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(AnalysisStatus::CREATED, $analysis->fresh()->status);
    }

    public function test_upload_while_processing_is_a_conflict(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->status(AnalysisStatus::ANALYZING)->create();

        $this->post($this->urlFor($analysis, '/dataset'), ['file' => $this->csvFile()], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ANALYSIS_BUSY');
    }

    public function test_reupload_clears_previous_derived_results(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->status(AnalysisStatus::ANALYZED)->create();
        Evidence::factory()->for($analysis)->create();

        $this->post($this->urlFor($analysis, '/dataset'), ['file' => $this->csvFile()], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseCount('evidence', 0);
        $this->assertSame(AnalysisStatus::UPLOADED, $analysis->fresh()->status);
    }

    public function test_sentinel_cell_value_never_reaches_the_log(): void
    {
        $logPath = storage_path('logs/leak-test-'.uniqid().'.log');
        config(['logging.default' => 'single', 'logging.channels.single.path' => $logPath, 'logging.channels.single.level' => 'debug']);

        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->create();

        $this->post($this->urlFor($analysis, '/dataset'), ['file' => $this->csvFile()], ['Accept' => 'application/json'])->assertCreated();
        logger()->info('upload received', ['analysis_id' => $analysis->public_id, 'file' => self::SENTINEL, 'rows' => [[self::SENTINEL]]]);

        $log = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);
        $this->assertNotSame('', $log, 'expected log output to be written');
        $this->assertStringNotContainsString(self::SENTINEL, $log);
        $this->assertStringContainsString('[redacted]', $log);
    }

    private function workbook(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ds').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($sheets as $i => $rows) {
            if ($i > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }
            // Real workbooks store dates as numbers with a date format; the reader turns those back into DateTime.
            $dateStyle = (new Style)->setFormat('yyyy-mm-dd hh:mm:ss');
            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v, $v instanceof \DateTimeInterface ? $dateStyle : null), $row)));
            }
        }
        $writer->close();

        return new UploadedFile($path, 'q1_sales.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_xlsx_upload_is_converted_to_csv_from_the_largest_worksheet(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->create();
        $file = $this->workbook([
            [['Notes'], ['Prepared for the board'], ['Figures in THB']], // cover sheet: 1 column, must be skipped
            [
                ['ยอดขาย_thb', 'Order Date', 'Units', 'Active', 'Note', ''],
                [1250.5, new \DateTimeImmutable('2025-03-01'), 3, true, 'a, "quoted"'],
                [],
                [99, new \DateTimeImmutable('2025-03-02 13:45:00'), 4.0, false, null, 'ragged extra'],
                [null, null, null, null, null],
            ],
            [['other', 'sheet'], [1, 2]],
        ]);

        $response = $this->post($this->urlFor($analysis, '/dataset'), ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'UPLOADED')
            ->assertJsonPath('data.dataset.file_name', 'q1_sales.xlsx')
            ->assertJsonPath('meta.source.format', 'xlsx')
            ->assertJsonPath('meta.source.sheet', 'Sheet2')
            ->assertJsonPath('meta.source.sheets_total', 3)
            ->assertJsonPath('meta.source.rows', 2)
            ->assertJsonPath('meta.source.columns', 5);
        $csv = Storage::disk('transient')->get('analyses/'.$analysis->public_id.'/dataset.csv');
        $this->assertSame(implode("\n", [
            'ยอดขาย_thb,"Order Date",Units,Active,Note',
            '1250.5,2025-03-01,3,true,"a, ""quoted"""',
            '99,"2025-03-02 13:45:00",4,false,',
            '',
        ]), $csv);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $response->json('data.dataset.sha256'));
        $this->assertDatabaseHas('analysis_stages', ['analysis_id' => $analysis->id, 'stage' => 'UPLOAD', 'status' => 'SUCCEEDED']);
    }

    public function test_unreadable_or_empty_workbooks_are_rejected_with_a_specific_code(): void
    {
        $user = $this->actingAsUser();
        $analysis = Analysis::factory()->for($user)->create();
        $url = $this->urlFor($analysis, '/dataset');

        $broken = UploadedFile::fake()->createWithContent('broken.xlsx', 'PK\x03\x04 this is not a workbook');
        $this->post($url, ['file' => $broken], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SPREADSHEET_UNREADABLE');

        $this->post($url, ['file' => $this->workbook([[[null, null]], [['header only']]])], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SPREADSHEET_EMPTY');

        $this->assertSame(AnalysisStatus::CREATED, $analysis->fresh()->status);
        Storage::disk('transient')->assertMissing('analyses/'.$analysis->public_id.'/dataset.csv');
    }
}
