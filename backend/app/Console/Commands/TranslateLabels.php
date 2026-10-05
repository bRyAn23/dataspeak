<?php

namespace App\Console\Commands;

use App\Models\Analysis;
use App\Services\Ai\DisplayLabelTranslator;
use App\Services\Reports\DisplayLabels;
use App\Support\Locale;
use Illuminate\Console\Command;

/** FEAT-I18N-002: (re)build the English display-label map for an analysis, e.g. one analysed before this feature. */
class TranslateLabels extends Command
{
    protected $signature = 'dataspeak:translate-labels {analysis : public id} {--language=en : Target language (en, th, zh, my)} {--show : Print the resulting map}';

    protected $description = 'Translate non-Latin headers and category values of an analysis into English display labels';

    public function handle(DisplayLabelTranslator $translator): int
    {
        $analysis = Analysis::query()->where('public_id', $this->argument('analysis'))->first();
        if ($analysis === null) {
            $this->error('Analysis not found.');

            return self::FAILURE;
        }
        $locale = Locale::normalize($this->option('language'));
        $result = $translator->translate($analysis, $locale);
        $this->info(sprintf('%d of %d labels translated (ai_status %s, model %s).', $result['count'], $result['candidates'], $result['ai_status'], $result['model'] ?? '-'));
        if ($this->option('show')) {
            foreach (DisplayLabels::mapFor((array) $analysis->fresh()->label_translations, $locale) as $original => $english) {
                $this->line("  {$original} -> {$english}");
            }
        }

        return self::SUCCESS;
    }
}
