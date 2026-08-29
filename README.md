# laravel-boop

Send events to a self-hosted [Boop](https://github.com/chrisgreg/boop) server from Laravel.

## What is Boop

[Boop](https://github.com/chrisgreg/boop) is a tiny, self-hosted notification inbox for developers: one Go binary, one SQLite file, one Docker container. Your apps POST small JSON events with a project API key; the server stores them and pushes straight to Apple's APNs. There is no hosted relay, no account system, no telemetry, and it is MIT licensed.

Use it to replace hosted push relays like ntfy/Pushover, Slack webhooks, or hand-rolled APNs plumbing — you keep the delivery infrastructure on your own server, private and self-hosted, instead of sending your events through someone else's cloud.

This library does one thing — send events reliably without ever taking your application down.

- PHP 8.1+, Laravel `^10.0|^11.0|^12.0|^13.0`
- Composer package: `solution-forest/laravel-boop`
- Namespace: `SolutionForest\Boop`

## Quick start

Spin up a local Boop instance, then point this package at it.

**1. Start a Boop server** (either way):

Option A — the upstream repo:

```bash
git clone https://github.com/chrisgreg/boop
cd boop
cp .env.example .env
mkdir -p data
docker compose up -d --build
```

Option B — this repo's ready-made compose file (builds the upstream server image):

```bash
mkdir -p data
docker compose -f docker-compose.boop.yml up -d --build
```

**2. Create a project** — open `http://localhost:8080`, follow the setup wizard, create a project, and copy the `boop_proj_...` API key it shows you.

**3. Wire up Laravel:**

```bash
composer require solution-forest/laravel-boop
```

```dotenv
# .env
BOOP_URL=http://localhost:8080
BOOP_API_KEY=boop_proj_...
```

```php
use SolutionForest\Boop\Facades\Boop;

Boop::send('Backup complete');
```

See [Usage](#usage) for richer events and [Async](#async) for fire-and-forget.

## Installation

```bash
composer require solution-forest/laravel-boop
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
| `BOOP_MAX_RETRIES` | `2` | Total attempts for network errors and 5xx (never 4xx); 2 = 1 initial + 1 retry. |
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

Note on per-call `overrides`: for `send()`, the `$overrides` array (`url`, `api_key`, `timeout`, ...) is applied to that call's request. For `sendAsync()`, overrides are applied while validating/redacting the event, but the actual HTTP POST runs inside the dispatched `SendEvent` job, which resolves the configured singleton — so `url` / `api_key` / timeout overrides do **not** propagate to the async request. If you need per-request overrides on an async send, dispatch the `SendEvent` job yourself (below) with a configured client.

### Queue-backed alternative

If you prefer a real queue worker, dispatch the `SendEvent` job yourself — this is the documented alternative and is **not** the default:

```php
use SolutionForest\Boop\Jobs\SendEvent;

SendEvent::dispatch(['title' => 'Backup complete'])->onQueue('boop');
```

## Behaviour guarantees

- **Never throws.** `send()` and `sendAsync()` catch everything.
- **Retries only network errors and 5xx**, up to `BOOP_MAX_RETRIES` total attempts with jittered exponential backoff; 4xx is never retried.
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
