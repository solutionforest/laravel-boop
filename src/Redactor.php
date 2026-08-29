<?php

namespace SolutionForest\Boop;

/**
 * Recursively replaces the values of sensitive keys inside event data with
 * "[REDACTED]". The server does this too, but the wire is the first place a
 * secret can leak. Matching is case-insensitive and treats "-" and "_" as
 * equivalent (mirrors boop's own redactor).
 */
class Redactor
{
    public const PLACEHOLDER = '[REDACTED]';

    public const DEFAULT_KEYS = [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'authorization',
        'cookie',
        'set-cookie',
        'private_key',
    ];

    /**
     * @param  array<array-key, mixed>  $value
     * @param  string[]  $extraKeys
     * @return array<array-key, mixed>
     */
    public static function apply(array $value, array $extraKeys = []): array
    {
        $keys = [];

        foreach ([...self::DEFAULT_KEYS, ...$extraKeys] as $key) {
            $keys[self::normalise((string) $key)] = true;
        }

        $seen = new \SplObjectStorage();

        return self::walk($value, $keys, $seen, 0);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, true>  $keys
     * @return array<array-key, mixed>
     */
    private static function walk(array $value, array $keys, \SplObjectStorage $seen, int $depth): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            if (isset($keys[self::normalise((string) $key)])) {
                $out[$key] = self::PLACEHOLDER;

                continue;
            }

            $out[$key] = self::redactValue($item, $keys, $seen, $depth);
        }

        return $out;
    }

    private static function redactValue(mixed $value, array $keys, \SplObjectStorage $seen, int $depth): mixed
    {
        if ($value instanceof \DateTimeInterface || $value instanceof \Stringable || is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                if ($depth > 50 || $seen->contains($value)) {
                    return $value;
                }

                $seen->attach($value);

                return is_array($serialized = $value->jsonSerialize())
                    ? self::walk($serialized, $keys, $seen, $depth + 1)
                    : $serialized;
            }

            if ($value instanceof \stdClass) {
                if ($depth > 50 || $seen->contains($value)) {
                    return $value;
                }

                $seen->attach($value);

                return self::walk((array) $value, $keys, $seen, $depth + 1);
            }

            return $value;
        }

        if (is_array($value)) {
            return self::walk($value, $keys, $seen, $depth + 1);
        }

        return $value;
    }

    private static function normalise(string $key): string
    {
        return str_replace('-', '_', strtolower(trim($key)));
    }
}
