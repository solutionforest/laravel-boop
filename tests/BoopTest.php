<?php

namespace SolutionForest\Boop\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use SolutionForest\Boop\Boop;
use SolutionForest\Boop\Jobs\SendEvent;

class BoopTest extends TestCase
{
    public function test_send_posts_to_events_endpoint_and_returns_ok(): void
    {
        Http::fake([
            'https://boop.test/api/v1/events' => Http::response(['id' => 'evt_123', 'created_at' => '2026-08-28T14:10:46.716098Z'], 201),
        ]);

        $result = app(Boop::class)->send('Backup complete');

        $this->assertTrue($result->ok);
        $this->assertSame('evt_123', $result->id);
        $this->assertSame('2026-08-28T14:10:46Z', $result->createdAt->format('Y-m-d\TH:i:s\Z'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://boop.test/api/v1/events'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer boop_proj_test')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['title'] === 'Backup complete'
            && $request['level'] === 'info');
    }

    public function test_send_returns_rejected_for_422_without_retry(): void
    {
        Http::fake([
            'https://boop.test/api/v1/events' => Http::response(['error' => 'invalid', 'message' => 'level must be one of ...'], 422),
        ]);

        $result = app(Boop::class)->send(['title' => 'x']);

        $this->assertTrue($result->failed());
        $this->assertSame('rejected', $result->error->errorCode);
        $this->assertSame(422, $result->error->status);
        Http::assertSentCount(1);
    }

    public function test_send_returns_unauthorized_for_401_without_retry(): void
    {
        Http::fake([
            'https://boop.test/api/v1/events' => Http::response(['error' => 'unauthorized', 'message' => 'bad key'], 401),
        ]);

        $result = app(Boop::class)->send('hello');

        $this->assertSame('unauthorized', $result->error->errorCode);
        $this->assertSame(401, $result->error->status);
        Http::assertSentCount(1);
    }

    public function test_send_retries_on_5xx_then_succeeds(): void
    {
        Http::fake([
            'https://boop.test/api/v1/events' => Http::sequence()
                ->push(['error' => 'internal', 'message' => 'boom'], 500)
                ->push(['id' => 'evt_456', 'created_at' => '2026-08-28T14:10:47Z'], 201),
        ]);

        $result = app(Boop::class)->send('retry me');

        $this->assertTrue($result->ok);
        $this->assertSame('evt_456', $result->id);
        Http::assertSentCount(2);
    }

    public function test_send_returns_server_error_after_retries_exhausted(): void
    {
        Http::fake([
            'https://boop.test/api/v1/events' => Http::response(['error' => 'internal', 'message' => 'boom'], 500),
        ]);

        $result = app(Boop::class)->send('boom');

        $this->assertTrue($result->failed());
        $this->assertSame('server_error', $result->error->errorCode);
        $this->assertSame(500, $result->error->status);
        Http::assertSentCount(2);
    }

    public function test_send_returns_unreachable_for_network_error_after_retries(): void
    {
        $attempts = 0;

        Http::fake([
            'https://boop.test/api/v1/events' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('connection refused');
            },
        ]);

        $result = app(Boop::class)->send('net');

        $this->assertTrue($result->failed());
        $this->assertSame('unreachable', $result->error->errorCode);
        $this->assertSame(2, $attempts);
    }

    public function test_send_never_throws_for_invalid_input(): void
    {
        Http::fake();

        $result = app(Boop::class)->send([]);

        $this->assertTrue($result->failed());
        $this->assertSame('invalid', $result->error->errorCode);
        Http::assertNothingSent();
    }

    public function test_send_returns_not_configured_when_url_missing(): void
    {
        Http::fake();

        $boop = new Boop(['url' => null, 'api_key' => 'k']);

        $result = $boop->send('x');

        $this->assertTrue($result->failed());
        $this->assertSame('not_configured', $result->error->errorCode);
        Http::assertNothingSent();
    }

    public function test_send_returns_not_configured_when_key_missing(): void
    {
        Http::fake();

        $boop = new Boop(['url' => 'https://boop.test', 'api_key' => null]);

        $result = $boop->send('x');

        $this->assertTrue($result->failed());
        $this->assertSame('not_configured', $result->error->errorCode);
    }

    public function test_send_is_disabled_noop_when_enabled_false(): void
    {
        Http::fake();

        $boop = new Boop(['url' => 'https://boop.test', 'api_key' => 'k', 'enabled' => false]);

        $result = $boop->send('x');

        $this->assertTrue($result->ok);
        $this->assertTrue($result->disabled);
        Http::assertNothingSent();
    }

    public function test_per_call_overrides(): void
    {
        Http::fake([
            'https://other.test/api/v1/events' => Http::response(['id' => 'evt_9', 'created_at' => '2026-08-28T14:10:47Z'], 201),
        ]);

        $result = app(Boop::class)->send('x', ['url' => 'https://other.test', 'api_key' => 'other_key']);

        $this->assertTrue($result->ok);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://other.test/api/v1/events'
            && $request->hasHeader('Authorization', 'Bearer other_key'));
    }

    public function test_sendAsync_dispatches_job_after_response(): void
    {
        Bus::fake();

        app(Boop::class)->sendAsync(['title' => 'async', 'level' => 'warning']);

        Bus::assertDispatchedAfterResponse(SendEvent::class, function (SendEvent $job) {
            return $job->payload['title'] === 'async'
                && $job->payload['level'] === 'warning';
        });
    }

    public function test_sendAsync_never_throws_on_invalid_input(): void
    {
        Bus::fake();
        Http::fake();

        $boop = app(Boop::class);

        try {
            $boop->sendAsync([]);
            $boop->sendAsync(['title' => 'x', 'level' => 'loud']);
        } catch (\Throwable $e) {
            $this->fail('sendAsync must never throw, got '.get_class($e).': '.$e->getMessage());
        }

        Bus::assertNotDispatched(SendEvent::class);
    }

    public function test_sendAsync_disabled_is_noop(): void
    {
        Bus::fake();
        Http::fake();

        $boop = new Boop(['url' => 'https://boop.test', 'api_key' => 'k', 'enabled' => false]);
        $boop->sendAsync('x');

        Bus::assertNotDispatched(SendEvent::class);
    }

    public function test_healthy_returns_true_when_server_ok(): void
    {
        Http::fake([
            'https://boop.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $this->assertTrue(app(Boop::class)->healthy());
    }

    public function test_healthy_returns_false_on_failure(): void
    {
        Http::fake([
            'https://boop.test/health' => Http::response(['status' => 'down'], 500),
        ]);

        $this->assertFalse(app(Boop::class)->healthy());
    }

    public function test_send_async_job_never_throws_and_logs_only_code_status_title_message(): void
    {
        Http::fake([
            'https://boop.test/api/v1/events' => Http::response(['error' => 'internal', 'message' => 'boom'], 500),
        ]);

        $records = [];
        $handler = new \Monolog\Handler\TestHandler();
        $logger = new \Monolog\Logger('test');
        $logger->pushHandler($handler);

        \Illuminate\Support\Facades\Log::swap($logger);

        $job = new SendEvent(['title' => 'job', 'data' => ['api_key' => 'sekrit']]);

        try {
            $job->handle(app(Boop::class));
        } catch (\Throwable $e) {
            $this->fail('job must never throw, got '.get_class($e).': '.$e->getMessage());
        }

        $records = $handler->getRecords();

        $this->assertCount(1, $records);
        $this->assertSame('WARNING', strtoupper($records[0]['level_name'] ?? ''));
        $this->assertSame('boop.send_failed', $records[0]['message']);
        $this->assertSame('server_error', $records[0]['context']['code']);
        $this->assertSame(500, $records[0]['context']['status']);
        $this->assertSame('job', $records[0]['context']['title']);
        $this->assertArrayHasKey('message', $records[0]['context']);
        $this->assertArrayNotHasKey('api_key', $records[0]['context']);
        $this->assertStringNotContainsString('sekrit', json_encode($records));
    }
}
