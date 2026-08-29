<?php

namespace SolutionForest\Boop\Exceptions;

use RuntimeException;

/**
 * Why a send failed. Returned inside a {@see \SolutionForest\Boop\Result};
 * never thrown by send() / sendAsync().
 */
class BoopError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->errorCode === 'server_error' || $this->errorCode === 'unreachable';
    }

    public static function fromResponse(int $status, mixed $body): self
    {
        $message = is_array($body) && is_string($body['message'] ?? null)
            ? $body['message']
            : "request failed ({$status})";

        $code = match (true) {
            $status === 401 => 'unauthorized',
            $status === 400, $status === 413, $status === 422 => 'rejected',
            $status >= 500 => 'server_error',
            default => 'unexpected',
        };

        return new self($code, $message, $status, is_array($body) ? ($body['error'] ?? null) : null);
    }

    public static function fromThrowable(\Throwable $e, string $code = 'unexpected'): self
    {
        if ($e instanceof self) {
            return $e;
        }

        return new self($code, $e->getMessage(), null, $e);
    }
}
