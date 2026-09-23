<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

final readonly class OutboundCompressedBatch
{
    public function __construct(
        public int $sequence,
        public int $sessionGeneration,
        public ?string $payload,
        public ?string $failureCode,
        public bool $synchronousFallback,
    ) {
        if ($sequence < 1 || $sessionGeneration < 1 || (($payload === null) === ($failureCode === null))) {
            throw new \InvalidArgumentException('Outbound compressed batch result is invalid.');
        }
    }

    public function isSuccess(): bool
    {
        return $this->payload !== null;
    }
}
