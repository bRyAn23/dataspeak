<?php

namespace App\Console\Commands;

use App\Enums\AiGenerationStatus;
use App\Models\Analysis;
use App\Services\Ai\AiGateway;
use App\Services\Ai\PlaceholderResolver;
use App\Services\Validation\Contracts\ClaimValidator;
use Illuminate\Console\Command;

/**
 * Evaluation harness for the capstone: runs the live model N times over an analysed dataset and
 * reports schema-valid rate, claim acceptance rate, rejection reasons by rule, latency and tokens.
 * Nothing is persisted (generations are built in memory).
 */
class AiEvaluate extends Command
{
    protected $signature = 'dataspeak:ai-eval {analysis : public id of an ANALYZED or COMPLETED analysis} {--runs=3} {--json}';

    protected $description = 'Evaluate AI interpretation + claim validation against the live model';

    public function handle(AiGateway $gateway, ClaimValidator $validator): int
    {
        $publicId = (string) $this->argument('analysis');
        if (! preg_match('/^[0-9a-f-]{36}$/i', $publicId)) {
            $this->error('Pass the analysis public id (UUID).');

            return self::FAILURE;
        }
        $analysis = Analysis::query()->where('public_id', $publicId)->first();
        if ($analysis === null || $analysis->evidence()->doesntExist()) {
            $this->error('Analysis not found or has no evidence. Run the deterministic analysis first.');

            return self::FAILURE;
        }
        $runs = max(1, (int) $this->option('runs'));
        $resolver = PlaceholderResolver::for($analysis);

        $stats = ['runs' => $runs, 'schema_valid' => 0, 'failures' => [], 'claims' => 0, 'supported' => 0, 'rejected' => 0, 'needs_review' => 0,
            'reasons' => [], 'normalisations' => [], 'latency_ms' => [], 'input_tokens' => [], 'output_tokens' => [], 'model' => null, 'prompt_version' => null];

        for ($i = 1; $i <= $runs; $i++) {
            $generation = $gateway->interpret($analysis, $resolver, persist: false);
            $stats['model'] = $generation->model;
            $stats['prompt_version'] = $generation->prompt_version;
            $stats['latency_ms'][] = (int) $generation->duration_ms;
            if ($generation->status !== AiGenerationStatus::SUCCEEDED) {
                $stats['failures'][] = $generation->error_code;
                $this->line("run {$i}: FAILED {$generation->error_code}");

                continue;
            }
            $stats['schema_valid']++;
            $stats['input_tokens'][] = (int) $generation->input_tokens;
            $stats['output_tokens'][] = (int) $generation->output_tokens;
            $items = array_merge((array) ($generation->response_payload['claims'] ?? []), (array) ($generation->response_payload['limitations'] ?? []));
            $runSupported = 0;
            $first = array_map(fn ($item) => $validator->validate($item, $resolver), $items);
            $repairable = [];
            foreach ($first as $k => $r) {
                $codes = array_map(fn ($x) => explode(':', $x)[0], $r->reasons);
                if ($r->status->value === 'REJECTED' && $codes !== [] && array_diff($codes, ['DIGITS_OUTSIDE_PLACEHOLDER', 'PLACEHOLDER_FIELD_NOT_ALLOWED', 'PLACEHOLDER_UNRESOLVABLE', 'PLACEHOLDER_UNKNOWN_EVIDENCE']) === []) {
                    $repairable[$k] = $items[$k];
                }
            }
            if ($repairable !== []) {
                $rewritten = collect($gateway->repair($generation, array_values($repairable), $resolver))->keyBy('claim_key');
                foreach ($repairable as $k => $orig) {
                    $cand = $rewritten->get((string) ($orig['claim_key'] ?? ''));
                    if ($cand !== null && $validator->validate($cand, $resolver)->status->value !== 'REJECTED') {
                        $items[$k] = $cand;
                        $stats['repaired'] = ($stats['repaired'] ?? 0) + 1;
                    }
                }
            }
            foreach ($items as $item) {
                $result = $validator->validate($item, $resolver);
                $stats['claims']++;
                $bucket = strtolower($result->status->value);
                $stats[$bucket === 'supported' ? 'supported' : ($bucket === 'rejected' ? 'rejected' : 'needs_review')]++;
                $runSupported += $result->accepted() ? 1 : 0;
                foreach ($result->reasons as $reason) {
                    $rule = explode(':', $reason)[0];
                    $bucketKey = in_array($rule, ['CITATION_COMPLETED', 'RECLASSIFIED_AS_CAVEAT', 'REPAIRED'], true) ? 'normalisations' : 'reasons';
                    $stats[$bucketKey][$rule] = ($stats[$bucketKey][$rule] ?? 0) + 1;
                }
            }
            $this->line(sprintf('run %d: %d claims, %d supported, %d ms', $i, count($items), $runSupported, $generation->duration_ms));
        }

        $avg = fn (array $xs) => $xs ? round(array_sum($xs) / count($xs)) : null;
        $summary = [
            'model' => $stats['model'], 'prompt_version' => $stats['prompt_version'], 'runs' => $runs,
            'schema_valid_rate' => round($stats['schema_valid'] / $runs, 3),
            'failures' => array_count_values($stats['failures']),
            'claims_total' => $stats['claims'],
            'claim_acceptance_rate' => $stats['claims'] ? round($stats['supported'] / $stats['claims'], 3) : null,
            'claims_by_status' => ['supported' => $stats['supported'], 'rejected' => $stats['rejected'], 'needs_review' => $stats['needs_review']],
            'claims_repaired' => $stats['repaired'] ?? 0,
            'rejection_reasons_by_rule' => $stats['reasons'],
            'normalisations_by_rule' => $stats['normalisations'],
            'avg_latency_ms' => $avg($stats['latency_ms']),
            'avg_input_tokens' => $avg($stats['input_tokens']),
            'avg_output_tokens' => $avg($stats['output_tokens']),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($summary as $k => $v) {
                $this->line(str_pad($k, 28).(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v));
            }
        }

        return self::SUCCESS;
    }
}
