<?php

namespace App\Console\Commands;

use App\Services\Lifecycle\StaleJobReaper;
use Illuminate\Console\Command;

/** Marks analyses whose background job died as FAILED (JOB_INTERRUPTED). Scheduled every minute. */
class ReapStaleJobs extends Command
{
    protected $signature = 'dataspeak:reap-stale-jobs {--force : Mark every processing analysis interrupted regardless of age} {--analysis= : Only this analysis public id}';

    protected $description = 'Mark analyses stuck in ANALYZING/REPORTING after a lost job as FAILED so they can be run again';

    public function handle(StaleJobReaper $reaper): int
    {
        $count = $reaper->reapAll((bool) $this->option('force'), $this->option('analysis') ?: null);
        $this->info(sprintf('%d analysis(es) marked interrupted (deadline %d s).', $count, $reaper->deadlineSeconds()));

        return self::SUCCESS;
    }
}
