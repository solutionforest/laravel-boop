<?php

namespace SolutionForest\Boop\Tests;

use SolutionForest\Boop\Enums\Level;
use SolutionForest\Boop\Event;
use SolutionForest\Boop\Exceptions\BoopError;

class EventTest extends TestCase
{
    public function test_title_string_becomes_payload(): void
    {
        $event = Event::new('Backup complete');

        $payload = $event->toPayload();

        $this->assertSame('Backup complete', $payload['title']);
        $this->assertSame('info', $payload['level']);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertEquals(new \stdClass(), $payload['data']);
        $this->assertArrayNotHasKey('body', $payload);
        $this->assertArrayNotHasKey('source', $payload);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['occurred_at']);
    }

    public function test_empty_data_encodes_as_json_object_not_array(): void
    {
        $event = Event::new('Backup complete');

        $this->assertSame('{"data":{}}', json_encode(['data' => $event->toPayload()['data']]));
    }

    public function test_full_event_normalised_to_wire_format(): void
    {
        $event = Event::new([
            'title' => 'Deploy complete',
            'body' => 'uini deployed',
            'level' => 'success',
            'source' => 'deploy',
            'type' => 'release',
            'external_id' => 'rel-42',
            'fingerprint' => 'deploy:uini',
            'data' => ['env' => 'production'],
        ]);

        $payload = $event->toPayload();

        $this->assertSame('Deploy complete', $payload['title']);
        $this->assertSame('success', $payload['level']);
        $this->assertSame('uini deployed', $payload['body']);
        $this->assertSame('deploy', $payload['source']);
        $this->assertSame('release', $payload['type']);
        $this->assertSame('rel-42', $payload['external_id']);
        $this->assertSame('deploy:uini', $payload['fingerprint']);
        $this->assertSame(['env' => 'production'], $payload['data']);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertSame(9, count($payload));
    }

    public function test_camel_case_keys_are_accepted(): void
    {
        $event = Event::new([
            'title' => 'x',
            'externalId' => 'rel-7',
            'occurredAt' => '2026-08-28T12:51:44Z',
        ]);

        $payload = $event->toPayload();

        $this->assertSame('rel-7', $payload['external_id']);
        $this->assertSame('2026-08-28T12:51:44.000000+00:00', $payload['occurred_at']);
    }

    public function test_level_enum_is_accepted(): void
    {
        $event = Event::new(['title' => 'x', 'level' => Level::Critical]);

        $this->assertSame('critical', $event->toPayload()['level']);
    }

    public function test_invalid_level_is_rejected(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('level must be one of');

        Event::new(['title' => 'x', 'level' => 'loud']);
    }

    public function test_missing_title_is_rejected(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionMessage('title is required');

        Event::new([]);
    }

    public function test_blank_title_is_rejected(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionMessage('title is required');

        Event::new(['title' => '   ']);
    }

    public function test_title_is_truncated_to_200_characters(): void
    {
        $title = str_repeat('a', 300);

        $event = Event::new($title);

        $this->assertSame(200, mb_strlen($event->title));
        $this->assertStringEndsWith('…', $event->title);
    }

    public function test_multibyte_title_truncation_counts_characters_not_bytes(): void
    {
        $title = str_repeat('界', 300);

        $event = Event::new($title);

        $this->assertSame(200, mb_strlen($event->title));
    }

    public function test_body_is_truncated_to_4000_characters(): void
    {
        $event = Event::new(['title' => 'x', 'body' => str_repeat('b', 5000)]);

        $this->assertSame(4000, mb_strlen($event->body));
    }

    public function test_short_fields_are_truncated_to_200_characters(): void
    {
        $event = Event::new([
            'title' => 'x',
            'source' => str_repeat('s', 300),
            'type' => str_repeat('t', 300),
            'external_id' => str_repeat('e', 300),
            'fingerprint' => str_repeat('f', 300),
        ]);

        $this->assertSame(200, mb_strlen($event->source));
        $this->assertSame(200, mb_strlen($event->type));
        $this->assertSame(200, mb_strlen($event->externalId));
        $this->assertSame(200, mb_strlen($event->fingerprint));
    }

    public function test_default_source_from_options(): void
    {
        $event = Event::new('hello', ['source' => 'cron']);

        $this->assertSame('cron', $event->toPayload()['source']);
    }

    public function test_invalid_occurred_at_is_rejected(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionMessage('occurred_at');

        Event::new(['title' => 'x', 'occurred_at' => 'not-a-date']);
    }

    public function test_data_must_be_an_object(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionMessage('data must be a JSON object');

        Event::new(['title' => 'x', 'data' => [1, 2, 3]]);
    }

    public function test_data_is_redacted_before_send(): void
    {
        $event = Event::new(['title' => 'x', 'data' => [
            'user' => ['password' => 'secret', 'api_key' => 'k'],
            'token' => 'abc',
            'username' => 'alice',
        ]]);

        $this->assertSame([
            'user' => ['password' => '[REDACTED]', 'api_key' => '[REDACTED]'],
            'token' => '[REDACTED]',
            'username' => 'alice',
        ], $event->data);
    }

    public function test_oversized_data_is_dropped_with_note(): void
    {
        $event = Event::new([
            'title' => 'x',
            'body' => 'original',
            'data' => ['blob' => str_repeat('z', 300 * 1024)],
        ]);

        $this->assertSame([], $event->data);
        $this->assertStringContainsString('original', $event->body);
        $this->assertStringContainsString('[data omitted: over 256 KB or not JSON-serialisable]', $event->body);
    }

    public function test_data_dropped_when_body_present_keeps_both_with_newline(): void
    {
        $event = Event::new([
            'title' => 'x',
            'data' => ['blob' => str_repeat('z', 300 * 1024)],
        ]);

        $this->assertSame('[data omitted: over 256 KB or not JSON-serialisable]', $event->body);
    }

    public function test_non_json_serialisable_data_is_dropped_with_note(): void
    {
        $resource = fopen('php://memory', 'r');

        $event = Event::new([
            'title' => 'x',
            'body' => 'original',
            'data' => ['stream' => $resource],
        ]);

        fclose($resource);

        $this->assertSame([], $event->data);
        $this->assertStringContainsString('original', $event->body);
        $this->assertStringContainsString('not JSON-serialisable', $event->body);
    }

    public function test_actions_are_validated(): void
    {
        $event = Event::new(['title' => 'x', 'actions' => [
            ['label' => 'Open', 'url' => 'https://example.com'],
        ]]);

        $this->assertSame([['label' => 'Open', 'url' => 'https://example.com']], $event->actions);
    }

    public function test_too_many_actions_are_rejected(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionMessage('at most 3 actions');

        Event::new(['title' => 'x', 'actions' => [
            ['label' => 'a', 'url' => 'https://a.test'],
            ['label' => 'b', 'url' => 'https://b.test'],
            ['label' => 'c', 'url' => 'https://c.test'],
            ['label' => 'd', 'url' => 'https://d.test'],
        ]]);
    }

    public function test_blocked_action_url_scheme_is_rejected(): void
    {
        $this->expectException(BoopError::class);
        $this->expectExceptionMessage('allowed scheme');

        Event::new(['title' => 'x', 'actions' => [
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
        ]]);
    }
}
