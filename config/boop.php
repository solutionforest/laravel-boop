<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Boop Server URL
    |--------------------------------------------------------------------------
    |
    | e.g. https://boop.example.com (trailing slash is stripped).
    |
    */

    'url' => env('BOOP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Project API Key
    |--------------------------------------------------------------------------
    |
    | Starts with "boop_proj_". One key per project; treated as a secret —
    | never logged and never written into this file.
    |
    */

    'api_key' => env('BOOP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Default source
    |--------------------------------------------------------------------------
    |
    | Tagged onto every event unless the event provides its own source,
    | e.g. "uini", "deploy", "cron".
    |
    */

    'source' => env('BOOP_SOURCE'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('BOOP_TIMEOUT', 10),

    'connect_timeout' => (int) env('BOOP_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Enabled flag
    |--------------------------------------------------------------------------
    |
    | When false, send() and sendAsync() no-op instead of hitting the server.
    | Defaults to true; disable in local/test/dev environments.
    |
    */

    'enabled' => filter_var(env('BOOP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Retried only for network errors and 5xx responses (never 4xx).
    | max_retries is the number of additional attempts after the first
    | (so 2 = up to 3 requests total). retry_delay is the base backoff in
    | milliseconds; jitter is applied per attempt.
    |
    */

    'max_retries' => (int) env('BOOP_MAX_RETRIES', 2),

    'retry_delay' => (int) env('BOOP_RETRY_DELAY', 200),

    /*
    |--------------------------------------------------------------------------
    | Extra keys to redact inside event data
    |--------------------------------------------------------------------------
    |
    | Sensitive keys replaced with "[REDACTED]" before transmission, in
    | addition to the built-in default list. Case-insensitive; "-" and "_"
    | are treated as equivalent.
    |
    */

    'redact_keys' => [],

];
