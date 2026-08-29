<?php

namespace SolutionForest\Boop\Enums;

/**
 * The levels Boop accepts. `critical` produces a time-sensitive push.
 */
enum Level: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /**
     * Coerce a string or an existing Level into a Level.
     */
    public static function coerce(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return self::tryFrom($value);
        }

        return null;
    }
}
