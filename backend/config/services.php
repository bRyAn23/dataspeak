<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Internal deterministic analytics service (FastAPI). Laravel is its only caller.
    | Token is shared via ANALYTICS_INTERNAL_TOKEN and sent as X-Internal-Token.
    */
    'analytics' => [
        'base_url' => env('ANALYTICS_BASE_URL', 'http://127.0.0.1:8001'),
        'token' => env('ANALYTICS_INTERNAL_TOKEN'),
        'timeout' => (int) env('ANALYTICS_TIMEOUT_SECONDS', 30),
    ],

    /*
    | Local LLM served by Ollama (MOD-09). Internal-only; never exposed publicly. A vLLM
    | (OpenAI-compatible) client can replace OllamaClient behind App\Services\Ai\Contracts\LlmClient.
    */
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3:8b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 300),
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.1),
        'seed' => (int) env('OLLAMA_SEED', 42),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
        'think' => filter_var(env('OLLAMA_THINK', false), FILTER_VALIDATE_BOOL),
    ],

    // Claude Messages API (AI_LLM_DRIVER=anthropic). Receives evidence and labels, never dataset rows.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
        'temperature' => (float) env('ANTHROPIC_TEMPERATURE', 0.1),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
        'context_tokens' => (int) env('ANTHROPIC_CONTEXT_TOKENS', 150000),
    ],

];
