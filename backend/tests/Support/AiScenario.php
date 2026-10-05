<?php

namespace Tests\Support;

use App\Enums\AnalysisFamily;
use App\Enums\AnalysisStatus;
use App\Enums\InsightType;
use App\Enums\QualityStatus;
use App\Models\Analysis;
use App\Models\DatasetColumn;
use App\Models\Evidence;
use App\Models\FieldDefinition;
use App\Models\Insight;
use App\Models\User;
use App\Models\Visualization;

/** Builds an ANALYZED analysis with Thai/Myanmar business names and evidence for AI tests. */
class AiScenario
{
    public const EV_GROWTH = 'ev_aaaaaaaaaaa1';   // average_period_growth, positive

    public const EV_DROP = 'ev_aaaaaaaaaaa2';     // latest_period_change, negative

    public const EV_SHARE = 'ev_aaaaaaaaaaa3';    // top_group_share with top_group

    public const EV_MEAN = 'ev_aaaaaaaaaaa4';     // descriptive mean, ACCEPTABLE

    public const EV_NULLS = 'ev_aaaaaaaaaaa5';    // quality null_rate, WARNING

    public const EV_INSUFF = 'ev_aaaaaaaaaaa6';   // trend slope, INSUFFICIENT

    public static function build(?User $user = null, string $revenueLabel = 'ยอดขาย', string $regionLabel = 'ဒေသ'): Analysis
    {
        $analysis = Analysis::factory()->for($user ?? User::factory()->create())->status(AnalysisStatus::ANALYZED)->create(['name' => 'Retail 2025', 'row_count' => 240, 'column_count' => 3]);
        $analysis->forceFill(['label_translations' => ['en' => ['map' => [], 'ai_status' => 'not_needed']]])->save(); // the run already handled English labels
        $date = DatasetColumn::factory()->for($analysis)->create(['source_name' => 'order_date', 'position' => 0, 'inferred_type' => 'DATE']);
        $region = DatasetColumn::factory()->for($analysis)->create(['source_name' => 'region', 'position' => 1, 'inferred_type' => 'CATEGORY']);
        $revenue = DatasetColumn::factory()->for($analysis)->create(['source_name' => 'revenue_thb', 'position' => 2]);
        FieldDefinition::factory()->for($date, 'column')->create(['business_name' => 'Order Date', 'semantic_type' => 'DATE']);
        FieldDefinition::factory()->for($region, 'column')->create(['business_name' => $regionLabel, 'semantic_type' => 'CATEGORY']);
        FieldDefinition::factory()->for($revenue, 'column')->create(['business_name' => $revenueLabel, 'semantic_type' => 'CURRENCY', 'unit' => 'THB', 'aggregation' => 'SUM']);

        $mk = fn (array $attrs) => Evidence::factory()->for($analysis)->create($attrs);
        $growth = $mk(['evidence_key' => self::EV_GROWTH, 'analysis_family' => AnalysisFamily::TREND, 'metric' => 'average_period_growth', 'method' => 'geometric_mean_growth',
            'value' => 0.032, 'display_value' => '+3.2%', 'source_columns' => ['order_date', 'revenue_thb'], 'metric_column_id' => $revenue->id, 'dimension_column_id' => $date->id,
            'parameters' => ['period' => 'month'], 'payload' => ['first_period' => '2025-01', 'last_period' => '2025-12']]);
        $mk(['evidence_key' => self::EV_DROP, 'analysis_family' => AnalysisFamily::TREND, 'metric' => 'latest_period_change', 'method' => 'period_over_period_change',
            'value' => -0.472, 'display_value' => '-47.2%', 'source_columns' => ['order_date', 'revenue_thb'], 'metric_column_id' => $revenue->id, 'dimension_column_id' => $date->id,
            'parameters' => ['period' => 'month', 'from' => '2025-11', 'to' => '2025-12']]);
        $share = $mk(['evidence_key' => self::EV_SHARE, 'analysis_family' => AnalysisFamily::GROUP_COMPARISON, 'metric' => 'top_group_share', 'method' => 'share_of_total',
            'value' => 0.543, 'display_value' => '54.3%', 'source_columns' => ['region', 'revenue_thb'], 'metric_column_id' => $revenue->id, 'dimension_column_id' => $region->id,
            'parameters' => ['aggregation' => 'sum'], 'payload' => ['top_group' => 'North', 'top_sum' => 7600000.0, 'total' => 14000000.0, 'unit' => 'THB']]);
        $mk(['evidence_key' => self::EV_MEAN, 'metric' => 'mean', 'method' => 'arithmetic_mean', 'value' => 292.4, 'display_value' => '292.4 THB',
            'source_columns' => ['revenue_thb'], 'metric_column_id' => $revenue->id]);
        $mk(['evidence_key' => self::EV_NULLS, 'analysis_family' => AnalysisFamily::QUALITY, 'metric' => 'null_rate', 'method' => 'missing_value_share',
            'value' => 0.146, 'display_value' => '14.6%', 'source_columns' => ['revenue_thb'], 'metric_column_id' => $revenue->id, 'quality_status' => QualityStatus::WARNING]);
        $mk(['evidence_key' => self::EV_INSUFF, 'analysis_family' => AnalysisFamily::TREND, 'metric' => 'trend_slope', 'method' => 'ols_linear_trend',
            'value' => null, 'display_value' => null, 'source_columns' => ['order_date', 'revenue_thb'], 'quality_status' => QualityStatus::INSUFFICIENT]);

        $top = Insight::factory()->for($analysis)->for($share)->create(['rank' => 1, 'insight_type' => InsightType::CONCENTRATION, 'headline' => 'North accounts for 54.3% of revenue', 'supporting_evidence_keys' => [self::EV_SHARE]]);
        Visualization::factory()->for($analysis)->create(['insight_id' => $top->id, 'title' => 'Total revenue by region', 'evidence_keys' => [self::EV_SHARE]]);
        Insight::factory()->for($analysis)->for($growth)->create(['rank' => 2, 'insight_type' => InsightType::TREND_CHANGE, 'headline' => 'Revenue changed by +3.2% per month', 'supporting_evidence_keys' => [self::EV_GROWTH]]);

        return $analysis;
    }

    /** A schema-valid model output exercising every validation outcome. */
    public static function modelOutput(): array
    {
        return [
            'claims' => [
                ['claim_key' => 'c1', 'claim_kind' => 'EXECUTIVE_SUMMARY', 'text' => '{'.self::EV_SHARE.'.group} accounts for {'.self::EV_SHARE.'.display_value} of {'.self::EV_SHARE.'.label} across {'.self::EV_SHARE.'.dimension}.', 'evidence_ids' => [self::EV_SHARE], 'confidence_language' => 'descriptive'],
                ['claim_key' => 'c2', 'claim_kind' => 'FINDING', 'text' => '{'.self::EV_GROWTH.'.label} grew by {'.self::EV_GROWTH.'.display_value} per {'.self::EV_GROWTH.'.period} between {'.self::EV_GROWTH.'.period_from} and {'.self::EV_GROWTH.'.period_to}.', 'evidence_ids' => [self::EV_GROWTH], 'confidence_language' => 'descriptive'],
                ['claim_key' => 'c3', 'claim_kind' => 'FINDING', 'text' => 'Revenue reached 14.1 million THB in 2025 according to the data.', 'evidence_ids' => [self::EV_MEAN], 'confidence_language' => 'descriptive'],
                ['claim_key' => 'c4', 'claim_kind' => 'FINDING', 'text' => 'Revenue increased by {'.self::EV_DROP.'.display_value} in the latest month.', 'evidence_ids' => [self::EV_DROP], 'confidence_language' => 'descriptive'],
                ['claim_key' => 'c5', 'claim_kind' => 'FINDING', 'text' => 'The North region led because of strong demand, with {'.self::EV_SHARE.'.display_value} of sales.', 'evidence_ids' => [self::EV_SHARE], 'confidence_language' => 'descriptive'],
            ],
            'limitations' => [
                ['claim_key' => 'l1', 'claim_kind' => 'CAVEAT', 'text' => '{'.self::EV_NULLS.'.label} is missing in {'.self::EV_NULLS.'.display_value} of rows, so averages use the remaining rows.', 'evidence_ids' => [self::EV_NULLS], 'confidence_language' => 'descriptive'],
            ],
        ];
    }

    public static function ollamaResponse(array $content, int $promptTokens = 1500, int $evalTokens = 300): array
    {
        return [
            'model' => 'qwen3:8b', 'created_at' => '2026-10-04T00:00:00Z', 'done' => true, 'done_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => json_encode($content, JSON_UNESCAPED_UNICODE)],
            'total_duration' => 4_200_000_000, 'prompt_eval_count' => $promptTokens, 'eval_count' => $evalTokens,
        ];
    }
}
