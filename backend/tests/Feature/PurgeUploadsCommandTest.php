<?php

namespace Tests\Feature;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApiTestCase;

/** FEAT-UPL-003 TTL expiry path */
class PurgeUploadsCommandTest extends ApiTestCase
{
    public function test_expired_uploads_are_purged_and_recent_or_processing_ones_kept(): void
    {
        $expired = Analysis::factory()->withTransientFile()->status(AnalysisStatus::UPLOADED)->create(['csv_uploaded_at' => now()->subMinutes(120)]);
        $recent = Analysis::factory()->withTransientFile()->status(AnalysisStatus::UPLOADED)->create(['csv_uploaded_at' => now()->subMinutes(5)]);
        $processing = Analysis::factory()->withTransientFile()->status(AnalysisStatus::ANALYZING)->create(['csv_uploaded_at' => now()->subMinutes(120)]);
        foreach ([$expired, $recent, $processing] as $a) {
            $this->placeTransientFile($a);
        }
        Storage::disk('transient')->put('analyses/orphan-dir/dataset.csv', 'x');
        touch(Storage::disk('transient')->path('analyses/orphan-dir/dataset.csv'), now()->subHours(3)->getTimestamp());

        $this->artisan('dataspeak:purge-uploads', ['--ttl' => 60])->assertSuccessful();

        Storage::disk('transient')->assertMissing('analyses/'.$expired->public_id.'/dataset.csv');
        Storage::disk('transient')->assertExists('analyses/'.$recent->public_id.'/dataset.csv');
        Storage::disk('transient')->assertExists('analyses/'.$processing->public_id.'/dataset.csv');
        Storage::disk('transient')->assertMissing('analyses/orphan-dir/dataset.csv');
        $this->assertNotNull($expired->fresh()->csv_purged_at);
        $this->assertNull($recent->fresh()->csv_purged_at);
    }
}
