<?php

namespace App\Console\Commands;

use App\Services\Uploads\TransientUploadStore;
use Illuminate\Console\Command;

/** FEAT-UPL-003: removes transient CSV uploads older than UPLOAD_TTL_MINUTES. Scheduled every five minutes. */
class PurgeExpiredUploads extends Command
{
    protected $signature = 'dataspeak:purge-uploads {--ttl= : Override TTL in minutes}';

    protected $description = 'Delete expired transient CSV uploads and orphan upload directories';

    public function handle(TransientUploadStore $store): int
    {
        $ttl = $this->option('ttl') !== null ? (int) $this->option('ttl') : (int) config('dataspeak.upload.ttl_minutes', 60);
        $count = $store->purgeExpired($ttl);
        $this->info("Purged {$count} transient upload(s) older than {$ttl} minutes.");

        return self::SUCCESS;
    }
}
