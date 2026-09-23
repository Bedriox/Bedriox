<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Server\Worker\WorkerReceipt;

/** @internal Mutable only while owned by one main-process compression queue. */
final class PendingOutboundCompression
{
    public ?WorkerReceipt $receipt = null;
    public ?string $result = null;
    public ?string $failureCode = null;
    public bool $synchronousFallback = false;
    public int $attempts = 0;

    public function __construct(
        public readonly int $sequence,
        public readonly int $sessionGeneration,
        public readonly string $encodedRequest,
        public readonly ?int $deadlineNanoseconds,
    ) {}

    public function isComplete(): bool
    {
        return $this->result !== null || $this->failureCode !== null;
    }
}
