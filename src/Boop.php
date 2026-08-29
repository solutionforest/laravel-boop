<?php

namespace SolutionForest\Boop;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SolutionForest\Boop\Exceptions\BoopError;
use SolutionForest\Boop\Jobs\SendEvent;

/**
 * Send events to a self-hosted Boop server. Never throws: bad input, network
 * failures and server errors all come back as a {@see Result}.
 *
 *     Boop::send('Backup complete');
 *     Boop::send(['title' => 'Payment received', 'level' => Level::Success, 'data' => ['amount' => 19.99]]);
 *     Boop::sendAsync('Cron finished'); // after the response, never throws
 */
class Boop
{
    public const VERSION = '1.0.0';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config = [])
    {
    }

    /**
     * Send an event and wait for the server. Returns a Result; never throws.
     */
    public function send(string|array $event, array $overrides = []): Result
    {
        try {
            if (! $this->enabled($overrides)) {
                return Result::disabled();
            }

            $event = Event::new($event, $this->eventOptions($overrides));

            return $this->post($event->toPayload(), $overrides);
        } catch (BoopError $e) {
            return Result::failure($e);
        } catch (\Throwable $e) {
            return Result::failure(BoopError::fromThrowable($e));
        }
    }

    /**
     * Fire and forget. Validates and redacts now, then dispatches the payload
     * to run after the response has been sent (never silently dropped; runs
     * immediately when there is no HTTP context). Failures are logged with
     * only code/status/title/message. Never throws.
     */
    public function sendAsync(string|array $event, array $overrides = []): void
    {
        $title = $this->titleOf($event);

        try {
            if (! $this->enabled($overrides)) {
                return;
            }

            $event = Event::new($event, $this->eventOptions($overrides));

            Bus::dispatchAfterResponse(new SendEvent($event->toPayload()));
        } catch (BoopError $e) {
            $this->logFailure($e, $title);
        } catch (\Throwable $e) {
            $this->logFailure(BoopError::fromThrowable($e), $title);
        }
    }

    /**
     * GET /health — true when the server answers {"status": "ok"}.
     */
    public function healthy(array $overrides = []): bool
    {
        try {
            $url = $this->config('url', null, $overrides);

            if (! $url) {
                return false;
            }

            $response = Http::timeout($this->config('timeout', 10, $overrides))
                ->connectTimeout($this->config('connect_timeout', 5, $overrides))
                ->get(rtrim($url, '/').'/health');

            return $response->status() === 200 && $response->json('status') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    public function enabled(array $overrides = []): bool
    {
        return (bool) $this->config('enabled', true, $overrides);
    }

    /**
     * Blocking POST to /api/v1/events. Used by the async job.
     *
     * @param  array<string, mixed>  $payload
     */
    public function post(array $payload, array $overrides = []): Result
    {
        $url = $this->config('url', null, $overrides);
        $apiKey = $this->config('api_key', null, $overrides);

        if (! $url || ! $apiKey) {
            return Result::failure(new BoopError('not_configured', 'Boop url and api_key are not configured (set BOOP_URL and BOOP_API_KEY)'));
        }

        try {
            $response = Http::timeout($this->config('timeout', 10, $overrides))
                ->connectTimeout($this->config('connect_timeout', 5, $overrides))
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Accept' => 'application/json',
                    'User-Agent' => 'laravel-boop/'.self::VERSION,
                ])
                ->retry(
                    $this->config('max_retries', 2, $overrides),
                    $this->retryDelay($overrides),
                    $this->retryWhen(),
                    false,
                )
                ->post(rtrim($url, '/').'/api/v1/events', $payload);
        } catch (ConnectionException $e) {
            return Result::failure(new BoopError('unreachable', $e->getMessage(), null, $e));
        } catch (RequestException $e) {
            return Result::failure(BoopError::fromResponse(
                $e->response?->status() ?? 0,
                $e->response?->json() ?? [],
            ));
        } catch (\Throwable $e) {
            return Result::failure(BoopError::fromThrowable($e));
        }

        if ($response->status() === 201) {
            $json = $response->json() ?? [];

            if (! is_string($json['id'] ?? null)) {
                return Result::failure(new BoopError('unexpected', 'malformed 201 response (missing id)', 201));
            }

            $createdAt = new \DateTimeImmutable($json['created_at'] ?? 'now');

            return Result::ok($json['id'], $createdAt);
        }

        return Result::failure(BoopError::fromResponse($response->status(), $response->json() ?? []));
    }

    public function config(string $key, mixed $default = null, array $overrides = []): mixed
    {
        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * Log a send failure using only code/status/title/message. The API key and
     * the payload are never logged.
     */
    public function logFailure(BoopError $error, string $title): void
    {
        try {
            Log::warning('boop.send_failed', [
                'code' => $error->errorCode,
                'status' => $error->status ?? '-',
                'title' => $title,
                'message' => $error->getMessage(),
            ]);
        } catch (\Throwable) {
            // never let logging crash the host
        }
    }

    /**
     * @return array{source: mixed, redact_keys: array}
     */
    protected function eventOptions(array $overrides): array
    {
        return [
            'source' => $this->config('source', null, $overrides),
            'redact_keys' => $this->config('redact_keys', [], $overrides),
        ];
    }

    protected function titleOf(string|array $event): string
    {
        if (is_string($event)) {
            return $event;
        }

        $title = $event['title'] ?? $event['external_id'] ?? '';

        return is_scalar($title) ? (string) $title : '';
    }

    /**
     * Jittered exponential backoff in milliseconds (e.g. 200, 400, 800).
     */
    protected function retryDelay(array $overrides): \Closure
    {
        return function (int $attempt) use ($overrides) {
            $base = (int) $this->config('retry_delay', 200, $overrides);

            if ($base <= 0) {
                return 0;
            }

            return $base * 2 ** ($attempt - 1) + random_int(0, 100);
        };
    }

    /**
     * Retry only network errors and 5xx responses; never 4xx.
     */
    protected function retryWhen(): \Closure
    {
        return function ($exception) {
            if ($exception instanceof ConnectionException) {
                return true;
            }

            if ($exception instanceof RequestException) {
                return (bool) $exception->response?->serverError();
            }

            return false;
        };
    }
}
