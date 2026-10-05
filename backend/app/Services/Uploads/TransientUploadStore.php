<?php

namespace App\Services\Uploads;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The only component that touches uploaded CSV bytes (FEAT-UPL-003, NFR-PRI-001).
 *
 * Files live on the "transient" disk under analyses/{public_id}/dataset.csv and are removed on
 * run completion, non-transient failure, analysis delete, or TTL expiry (purgeExpired).
 * Nothing here reads cell values; only size and a SHA-256 fingerprint are recorded.
 */
class TransientUploadStore
{
    public const FILE_NAME = 'dataset.csv';

    public function disk(): Filesystem
    {
        return Storage::disk('transient');
    }

    public function relativePath(Analysis $analysis): string
    {
        return 'analyses/'.$analysis->public_id.'/'.self::FILE_NAME;
    }

    public function absolutePath(Analysis $analysis): string
    {
        return $this->disk()->path($this->relativePath($analysis));
    }

    public function exists(Analysis $analysis): bool
    {
        return $this->disk()->exists($this->relativePath($analysis));
    }

    /** Details of the last store() call when the upload was a spreadsheet converted to CSV, else null. */
    public ?array $lastConversion = null;

    /** Stores the upload (an .xlsx is converted to CSV first), records metadata on the analysis (not saved), returns the fingerprint. */
    public function store(UploadedFile $file, Analysis $analysis): string
    {
        $dir = 'analyses/'.$analysis->public_id;
        $this->disk()->deleteDirectory($dir);
        $this->lastConversion = null;

        if (SpreadsheetConverter::isSpreadsheet($file)) {
            $this->disk()->makeDirectory($dir);
            $this->lastConversion = app(SpreadsheetConverter::class)->toCsv($file->getRealPath(), $this->absolutePath($analysis));
            Log::info('upload.converted', ['analysis_id' => $analysis->public_id] + array_diff_key($this->lastConversion, ['sheet' => 1]));
        } else {
            $this->disk()->putFileAs($dir, $file, self::FILE_NAME);
        }

        // Fingerprint of what the user sent (the workbook itself for Excel), not of the derived CSV.
        $sha256 = hash_file('sha256', $file->getRealPath()) ?: hash_file('sha256', $this->absolutePath($analysis));

        $analysis->fill([
            'source_file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'source_file_size_bytes' => $file->getSize(),
            'file_sha256' => $sha256,
            'csv_uploaded_at' => now(),
            'csv_purged_at' => null,
        ]);

        Log::info('upload.stored', ['analysis_id' => $analysis->public_id, 'size_bytes' => $file->getSize()]);

        return $sha256;
    }

    /** Deletes the transient file (idempotent) and records csv_purged_at. */
    public function delete(Analysis $analysis, string $reason): void
    {
        $this->disk()->deleteDirectory('analyses/'.$analysis->public_id);

        if ($analysis->exists && $analysis->csv_uploaded_at !== null && $analysis->csv_purged_at === null) {
            $analysis->forceFill(['csv_purged_at' => now()])->save();
        }

        Log::info('upload.purged', ['analysis_id' => $analysis->public_id, 'reason' => $reason]);
    }

    /** Removes uploads older than the TTL that are not mid-run, plus orphan directories. Returns the count. */
    public function purgeExpired(int $ttlMinutes): int
    {
        $cutoff = now()->subMinutes($ttlMinutes);
        $count = 0;

        Analysis::query()
            ->whereNotNull('csv_uploaded_at')
            ->whereNull('csv_purged_at')
            ->where('csv_uploaded_at', '<', $cutoff)
            ->whereNotIn('status', [AnalysisStatus::ANALYZING->value, AnalysisStatus::REPORTING->value])
            ->each(function (Analysis $analysis) use (&$count): void {
                $this->delete($analysis, 'ttl_expired');
                $count++;
            });

        $known = Analysis::query()->pluck('public_id')->all();
        foreach ($this->disk()->directories('analyses') as $dir) {
            $publicId = basename($dir);
            $lastModified = collect($this->disk()->allFiles($dir))->map(fn ($f) => $this->disk()->lastModified($f))->max();
            if (! in_array($publicId, $known, true) && ($lastModified === null || $lastModified < $cutoff->getTimestamp())) {
                $this->disk()->deleteDirectory($dir);
                $count++;
            }
        }

        return $count;
    }
}
