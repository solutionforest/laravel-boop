<?php

namespace SolutionForest\Boop;

use SolutionForest\Boop\Exceptions\BoopError;

/**
 * The outcome of a send. Never an exception.
 */
class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $id = null,
        public readonly ?\DateTimeInterface $createdAt = null,
        public readonly ?BoopError $error = null,
        public readonly bool $disabled = false,
    ) {
    }

    public static function ok(string $id, \DateTimeInterface $createdAt): self
    {
        return new self(true, $id, $createdAt);
    }

    public static function disabled(): self
    {
        return new self(true, null, null, null, true);
    }

    public static function failure(BoopError $error): self
    {
        return new self(false, null, null, $error);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }
}
