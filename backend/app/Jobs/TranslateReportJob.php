<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Services\Ai\ClaimTranslator;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Reports\DisplayLabels;
use App\Support\Locale;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FEAT-I18N-003 Pre-translation. Right after a report is written, its verified statements and labels are translated
 * into the other languages in the background, so switching the interface language is instant. Nothing is
 * re-interpreted; ClaimTranslator's acceptance rules apply. A cache flag tells the report resource that a
 * translation is in progress so the page polls instead of starting a second one. When the model times out or
 * leaves sentences untranslated, the job re-queues itself with a growing delay (the unique lock is released when
 * processing starts, so the retry is accepted) up to MAX_ATTEMPTS.
 */
class TranslateReportJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Below the worker's --timeout=600; several batches of a Burmese report can take a few minutes. */
    public int $timeout = 540;

    public int $uniqueFor = 900;

    /** Low-priority queue: the worker drains `default` (profiling, analysis, reports) before it takes a translation. */
    public const QUEUE = 'translations';

    public const MAX_ATTEMPTS = 3;

    public function __construct(public readonly int $analysisId, public readonly string $locale, public readonly int $attempt = 1) {}

    public function uniqueId(): string
    {
        return 'translate:'.$this->analysisId.':'.$this->locale;
    }

    public static function flagKey(Analysis $analysis, string $locale): string
    {
        return 'dataspeak:translate:'.$analysis->public_id.':'.Locale::normalize($locale);
    }

    public static function inProgress(Analysis $analysis, string $locale): bool
    {
        return Cache::has(self::flagKey($analysis, $locale));
    }

    public function handle(DisplayLabelTranslator $labels, ClaimTranslator $claims): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        $locale = Locale::normalize($this->locale);
        if ($analysis === null || $analysis->status !== AnalysisStatus::COMPLETED || $analysis->report === null) {
            return;
        }
        Cache::put(self::flagKey($analysis, $locale), 'running', now()->addMinutes(10));
        $retry = false;
        try {
            if (! isset(DisplayLabels::normalizeStored($analysis->label_translations)[$locale])) {
                $labels->translate($analysis, $locale);
            }
            $result = $claims->translate($analysis->fresh(), $locale, $this->attempt);
            $context = ['analysis_id' => $analysis->public_id, 'locale' => $locale, 'attempt' => $this->attempt, 'translated' => $result['translated'], 'pending' => $result['pending'], 'ai_status' => $result['ai_status']];
            $retry = $result['pending'] > 0 && in_array($result['ai_status'], ['unavailable', 'invalid_output'], true) && $this->attempt < self::MAX_ATTEMPTS;
            Log::info($retry ? 'report.pretranslate_retry' : 'report.pretranslated', $context);
        } catch (Throwable $e) {
            Log::warning('report.pretranslate_failed', ['analysis_id' => $analysis->public_id, 'locale' => $locale, 'attempt' => $this->attempt, 'method' => $e::class]);
        } finally {
            if ($retry) {
                Cache::put(self::flagKey($analysis, $locale), 'queued', now()->addMinutes(15));
                self::dispatch($analysis->id, $locale, $this->attempt + 1)->onQueue(self::QUEUE)->delay(now()->addSeconds(60 * $this->attempt));
            } else {
                Cache::forget(self::flagKey($analysis, $locale));
            }
        }
    }

    public function failed(?Throwable $e = null): void
    {
        $analysis = Analysis::query()->find($this->analysisId);
        if ($analysis !== null) {
            Cache::forget(self::flagKey($analysis, $this->locale));
        }
    }

    /** Queue a translation into every other supported language and raise the in-progress flags right away. */
    public static function dispatchForOtherLanguages(Analysis $analysis, string $reportLocale): void
    {
        foreach (Locale::SUPPORTED as $other) {
            if ($other === $reportLocale) {
                continue;
            }
            Cache::put(self::flagKey($analysis, $other), 'queued', now()->addMinutes(15));
            self::dispatch($analysis->id, $other)->onQueue(self::QUEUE);
        }
    }
}
