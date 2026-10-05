<?php

/*
|--------------------------------------------------------------------------
| Data Speak application settings
|--------------------------------------------------------------------------
*/

return [

    // Transient CSV handling (FEAT-UPL-003). Files live on the "transient" disk only.
    'upload' => [
        'max_mb' => (int) env('UPLOAD_MAX_MB', 50),
        'ttl_minutes' => (int) env('UPLOAD_TTL_MINUTES', 60),
        // CSV is the native format. An Excel workbook (.xlsx) is accepted and converted to CSV at upload time
        // (the worksheet with the largest table); everything downstream, including the analytics service, only ever sees CSV.
        'allowed_extensions' => ['csv', 'xlsx'],
        'allowed_mimetypes' => [
            'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream',
        ],
        'spreadsheet_extensions' => ['xlsx'],
    ],

    // Report and interface languages (FEAT-I18N-003). Codes are ISO 639-1; see App\Support\Locale.
    'languages' => ['en', 'th', 'zh', 'my'],

    // Where verification links land after the API has confirmed the address (the React app).
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    // Email verification (FEAT-AUTH-001). Unverified accounts can log in, read /me and resend the
    // email, but every analysis route answers 403 EMAIL_NOT_VERIFIED until the link is clicked.
    'require_verified_email' => filter_var(env('REQUIRE_VERIFIED_EMAIL', true), FILTER_VALIDATE_BOOL),
    'verification_link_minutes' => (int) env('VERIFICATION_LINK_MINUTES', 60),

    // After a successful deterministic run, continue straight into report generation. Off by default since
    // FEAT-VIS-003: the run stops at ANALYZED so the user can review the charts before the report is written.
    'auto_report' => filter_var(env('AUTO_REPORT', false), FILTER_VALIDATE_BOOL),

    // PROF-API-001 runs as a queued job (profiling + suggested definitions); hard timeout in seconds.
    'profile_job_timeout_seconds' => (int) env('PROFILE_JOB_TIMEOUT_SECONDS', 300),

    // A processing analysis whose running stage started more than job timeout + grace seconds ago is treated as
    // interrupted (worker killed, container restarted) and marked FAILED so the user can run the step again.
    'stale_job_grace_seconds' => (int) env('STALE_JOB_GRACE_SECONDS', 120),

    // Polling cadence advertised to clients (Laravel API doc Section 9).
    'status_poll_seconds' => 2,

    // Idempotency-Key cache lifetime (hours).
    'idempotency_ttl_hours' => 24,

    // Analysis pipeline options forwarded to the analytics service.
    'analysis_options' => [
        'seed' => 42,
        'max_insights' => 10,
    ],

    /*
    | AI interpretation (FEAT-AI-001/002) and rule-based claim validation (FEAT-VAL-001).
    | Numbers by reference: the model writes {ev_xxxxxxxxxxxx.field} placeholders, never digits.
    */
    'ai' => [
        'enabled' => filter_var(env('AI_ENABLED', true), FILTER_VALIDATE_BOOL),
        'driver' => env('AI_LLM_DRIVER', 'ollama'),
        'prompt_version' => env('AI_PROMPT_VERSION', 'exec-interp-v7'),
        'report_language' => env('AI_REPORT_LANGUAGE', 'English'),
        // After a report is written, translate it into the other languages in the background (FEAT-I18N-003).
        'pretranslate' => filter_var(env('PRETRANSLATE_REPORTS', true), FILTER_VALIDATE_BOOL),
        'max_schema_retries' => 1,
        'max_insights_in_prompt' => 10,
        'prompt_overhead_tokens' => 1200,
        'job_timeout_seconds' => (int) env('AI_JOB_TIMEOUT_SECONDS', 900),
        'validator' => env('AI_CLAIM_VALIDATOR', 'rule_based'),

        // The only evidence fields a placeholder may reference. Resolved by PlaceholderResolver.
        'placeholder_fields' => ['display_value', 'label', 'unit', 'dimension', 'group', 'period', 'period_from', 'period_to'],

        // Phrases that assert causation. Flagged for review, never rewritten silently.
        'causal_phrases' => [
            // Thai, Chinese and Burmese causal words (matched without word boundaries)
            'เพราะ', 'เนื่องจาก', 'ส่งผลให้', 'ทำให้', 'เป็นผลมาจาก', '因为', '由于', '导致', '造成', '使得', 'ကြောင့်', 'ဖြစ်စေသည်', 'အကျိုးဆက်အဖြစ်',
            'because of', 'because', 'caused by', 'caused', 'causes', 'cause of', 'led to', 'leads to', 'lead to',
            'drives', 'drove', 'driven by', 'driver of', 'results in', 'resulted in', 'result of', 'due to',
            'thanks to', 'owing to', 'as a result of', 'impact of', 'effect of', 'explains', 'attributable to',
        ],

        // Metrics whose sign carries a direction; used by the contradiction rule.
        'directional_metrics' => ['latest_period_change', 'average_period_growth', 'total_change', 'trend_slope', 'top_bottom_mean_difference'],
        'increase_words' => ['increase', 'increased', 'increasing', 'grew', 'grow', 'growth', 'rose', 'rise', 'rising', 'higher', 'gain', 'gained', 'improved', 'climbed', 'expanded'],
        'decrease_words' => ['decrease', 'decreased', 'decreasing', 'fell', 'fall', 'falling', 'dropped', 'drop', 'declined', 'decline', 'lower', 'shrank', 'reduced', 'reduction', 'contracted'],
    ],

];
