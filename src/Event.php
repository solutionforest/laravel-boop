<?php

namespace SolutionForest\Boop;

use DateTimeImmutable;
use DateTimeInterface;
use SolutionForest\Boop\Enums\Level;
use SolutionForest\Boop\Exceptions\BoopError;

/**
 * A validated, normalised Boop event. Build with {@see self::new()} and ship
 * over the wire with {@see self::toPayload()}.
 *
 * Only `title` is required. Strings that are too long are truncated rather
 * than rejected; `data` is redacted and dropped with a note when it cannot be
 * JSON-encoded or is over 256 KB.
 */
class Event
{
    public const MAX_TITLE = 200;
    public const MAX_BODY = 4000;
    public const MAX_SHORT = 200;
    public const MAX_DATA_BYTES = 256 * 1024;
    public const MAX_ACTIONS = 3;
    public const MAX_ACTION_LABEL = 40;

    public const DATA_OMITTED_NOTE = '[data omitted: over 256 KB or not JSON-serialisable]';

    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript'];

    public function __construct(
        public readonly string $title,
        public readonly Level $level,
        public readonly DateTimeInterface $occurredAt,
        public readonly array $data,
        public readonly ?string $body = null,
        public readonly ?string $source = null,
        public readonly ?string $type = null,
        public readonly ?string $externalId = null,
        public readonly ?string $fingerprint = null,
        public readonly ?array $actions = null,
    ) {
    }

    /**
     * Build an Event from a title string or an array of fields, applying the
     * client conventions (truncate-not-reject, redact, drop oversized data).
     *
     * @param  string|array{title?: mixed, body?: mixed, level?: mixed, source?: mixed, type?: mixed, external_id?: mixed, externalId?: mixed, fingerprint?: mixed, occurred_at?: mixed, occurredAt?: mixed, data?: mixed, actions?: mixed}  $input
     * @param  array{source?: ?string, redact_keys?: string[]}  $options
     *
     * @throws BoopError when the input is truly invalid (missing title, bad level, bad occurred_at, non-object data)
     */
    public static function new(string|array $input, array $options = []): self
    {
        $fields = is_string($input) ? ['title' => $input] : $input;

        if (! is_array($fields)) {
            throw new BoopError('invalid', 'event must be a title string or an object with a title');
        }

        $title = self::clip(self::str($fields['title'] ?? null), self::MAX_TITLE);
        if ($title === '') {
            throw new BoopError('invalid', 'title is required');
        }

        $level = Level::coerce($fields['level'] ?? 'info');
        if ($level === null) {
            throw new BoopError('invalid', 'level must be one of info, success, warning, error, critical');
        }

        $occurredAt = self::occurredAt($fields['occurred_at'] ?? $fields['occurredAt'] ?? null);

        $data = self::normaliseData($fields['data'] ?? null);
        $data = Redactor::apply($data, $options['redact_keys'] ?? []);

        $body = self::nullableClip(self::str($fields['body'] ?? null), self::MAX_BODY);
        $source = self::nullableClip(self::str($fields['source'] ?? $options['source'] ?? null), self::MAX_SHORT);
        $type = self::nullableClip(self::str($fields['type'] ?? null), self::MAX_SHORT);
        $externalId = self::nullableClip(self::str($fields['external_id'] ?? $fields['externalId'] ?? null), self::MAX_SHORT);
        $fingerprint = self::nullableClip(self::str($fields['fingerprint'] ?? null), self::MAX_SHORT);

        $actions = isset($fields['actions']) ? self::actions($fields['actions']) : null;

        [$data, $body] = self::fitData($data, $body);

        return new self($title, $level, $occurredAt, $data, $body, $source, $type, $externalId, $fingerprint, $actions);
    }

    /**
     * The JSON body sent to POST /api/v1/events.
     */
    public function toPayload(): array
    {
        $payload = [
            'title' => $this->title,
            'level' => $this->level->value,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'data' => $this->data === [] ? new \stdClass() : $this->data,
        ];

        foreach (['body', 'source', 'type', 'fingerprint'] as $field) {
            if ($this->{$field} !== null) {
                $payload[$field] = $this->{$field};
            }
        }

        if ($this->externalId !== null) {
            $payload['external_id'] = $this->externalId;
        }

        if ($this->actions !== null) {
            $payload['actions'] = $this->actions;
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function normaliseData(mixed $data): array
    {
        if ($data === null) {
            return [];
        }

        if ($data instanceof \JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        if (is_array($data)) {
            if (array_is_list($data) && $data !== []) {
                throw new BoopError('invalid', 'data must be a JSON object, not an array');
            }

            return $data;
        }

        if (is_object($data)) {
            return (array) $data;
        }

        throw new BoopError('invalid', 'data must be a JSON object');
    }

    private static function occurredAt(mixed $value): DateTimeInterface
    {
        if ($value === null || $value === '') {
            return new DateTimeImmutable();
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        try {
            return new DateTimeImmutable(is_string($value) ? $value : (string) $value);
        } catch (\Throwable) {
            throw new BoopError('invalid', 'occurred_at must be a DateTime or an RFC 3339 string');
        }
    }

    /**
     * @param  mixed  $value
     */
    private static function actions(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value) === false) {
            throw new BoopError('invalid', 'actions must be an array of {label, url}');
        }

        if (count($value) > self::MAX_ACTIONS) {
            throw new BoopError('invalid', 'at most 3 actions are allowed');
        }

        $out = [];

        foreach ($value as $action) {
            if (! is_array($action)) {
                throw new BoopError('invalid', 'each action must be {label, url}');
            }

            $label = self::clip(self::str($action['label'] ?? null), self::MAX_ACTION_LABEL);
            $url = self::str($action['url'] ?? null);

            if ($label === '') {
                throw new BoopError('invalid', 'action label is required');
            }

            if ($url === '') {
                throw new BoopError('invalid', 'action url is required');
            }

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if ($scheme === '' || in_array($scheme, self::BLOCKED_SCHEMES, true)) {
                throw new BoopError('invalid', 'action url must be absolute with an allowed scheme');
            }

            $out[] = ['label' => $label, 'url' => $url];
        }

        return $out;
    }

    /**
     * Fit redacted data into the 256 KB wire limit. When the JSON encoding
     * fails or is too large, drop the data and append a note to the body.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{0: array<array-key, mixed>, 1: ?string}
     */
    private static function fitData(array $data, ?string $body): array
    {
        $encoded = @json_encode($data);

        if ($encoded === false || strlen($encoded) > self::MAX_DATA_BYTES) {
            $note = self::DATA_OMITTED_NOTE;

            return [[], self::nullableClip($body !== null && $body !== '' ? $body."\n".$note : $note, self::MAX_BODY)];
        }

        return [$data, $body];
    }

    private static function str(mixed $value): string
    {
        if ($value instanceof \Stringable || is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private static function clip(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }

    private static function nullableClip(string $value, int $max): ?string
    {
        if ($value === '') {
            return null;
        }

        return self::clip($value, $max);
    }
}
