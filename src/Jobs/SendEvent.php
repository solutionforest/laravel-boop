<?php

namespace SolutionForest\Boop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SolutionForest\Boop\Boop;

/**
 * Sends an event payload through the Boop client. Dispatched after the
 * response by default; dispatch it directly to a queue for the queue-backed
 * alternative. Never throws.
 */
class SendEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
    }

    public function handle(Boop $boop): void
    {
        $result = $boop->post($this->payload);

        if ($result->failed()) {
            $boop->logFailure($result->error, (string) ($this->payload['title'] ?? ''));
        }
    }
}
