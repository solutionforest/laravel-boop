# laravel-boop

Send events to a self-hosted [Boop](https://github.com/chrisgreg/boop) server from Laravel.

Boop is a tiny notification inbox: your app POSTs a small JSON event, Boop stores it and pushes a notification to your phone. This library does one thing — send events reliably without ever taking your application down.

- PHP 8.1+, Laravel `^10.0|^11.0|^12.0|^13.0`
- Composer package: `solutionforest/laravel-boop`
- Namespace: `SolutionForest\Boop`

## Installation

```bash
composer require solutionforest/laravel-boop
```

The package auto-registers its service provider and facade alias (`Boop`).

Optionally publish the config:

```bash
php artisan vendor:publish --tag=boop-config
```

## Configuration

Set these in your `.env` (the API key must come from the environment, never from a committed file):

| Env var | Default | Notes |
| --- | --- | --- |
| `BOOP_URL` | — | e.g. `https://boop.example.com`. Trailing slash is stripped. |
| `BOOP_API_KEY` | — | Project key, `boop_proj_...`. Treated as a secret. |
| `BOOP_SOURCE` | — | Default `source` tagged onto every event. |
| `BOOP_ENABLED` | `true` | Set `false` to no-op instead of hitting the server. |
| `BOOP_TIMEOUT` | `10` | Total per-request timeout, seconds. |
| `BOOP_CONNECT_TIMEOUT` | `5` | Connect timeout, seconds. |
| `BOOP_MAX_RETRIES` | `2` | Retries for network errors and 5xx (never 4xx). |
| `BOOP_RETRY_DELAY` | `200` | Base backoff in ms, jittered exponentially. |

Extra keys to redact inside event `data` can be added via the `redact_keys` config entry.

## Usage

The minimal call works with just a title:

```php
use SolutionForest\Boop\Facades\Boop;

$result = Boop::send('Backup complete');

if ($result->ok) {
    echo $result->id; // "evt_..."
}
```

A richer event:

```php
use SolutionForest\Boop\Enums\Level;

$result = Boop::send([
    'title' => 'Payment received',
    'body' => '£19.99 from customer #123',
    'level' => Level::Success,
    'source' => 'billing',
    'data' => [
        'customer_id' => 123,
        'amount' => 19.99,
        'currency' => 'GBP',
    ],
    'actions' => [
        ['label' => 'Open order', 'url' => 'https://shop.example/orders/123'],
    ],
]);
```

`send()` blocks until the server answers. It **never throws**: bad input, missing configuration, network failures and server errors all come back as a `Result`:

- `Result::ok` → `$result->id`, `$result->createdAt`
- `Result::disabled` → sending is turned off
- `Result::failed` → `$result->error` is a `BoopError` (`->code()`, `->status`, `isRetryable()`, `->errorCode`)

## Async

`sendAsync()` is fire-and-forget for hot paths. It validates and redacts immediately, then runs after the response has been sent (via `dispatchAfterResponse()`), falling back to running immediately when there is no HTTP context — it never silently drops. Failures are logged with only `code` / `status` / `title` / `message`; never the key or payload.

```php
Boop::sendAsync('Cron finished');
Boop::sendAsync(['title' => 'Deploy failed', 'level' => 'error']);
```

### Queue-backed alternative

If you prefer a real queue worker, dispatch the `SendEvent` job yourself — this is the documented alternative and is **not** the default:

```php
use SolutionForest\Boop\Jobs\SendEvent;

SendEvent::dispatch(['title' => 'Backup complete'])->onQueue('boop');
```

## Behaviour guarantees

- **Never throws.** `send()` and `sendAsync()` catch everything.
- **Retries only network errors and 5xx**, at most `BOOP_MAX_RETRIES` times with jittered exponential backoff; 4xx is never retried.
- **Redacts before transmit.** The default sensitive keys (`password`, `token`, `api_key`, `authorization`, ... ) plus anything in `redact_keys` are replaced with `[REDACTED]` inside `data`, recursively and case-insensitively (`-`/`_` equivalent).
- **Truncates rather than rejects.** `title` (200), `body` (4000), short fields (200); character counts via `mb_*`.
- **Drops oversized `data`.** If `data` cannot be JSON-encoded or is over 256 KB after redaction, it is dropped and `[data omitted: over 256 KB or not JSON-serialisable]` is appended to `body`.
- **Timeouts everywhere.** Total and connect timeouts are configurable.
- **Health check.** `Boop::healthy()` hits `GET /health`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [LICENSE](LICENSE).
