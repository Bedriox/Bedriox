<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Batch\BatchLimits;
use InvalidArgumentException;

final readonly class LoginChannelLimits
{
    public function __construct(
        public BatchLimits $batch = new BatchLimits(),
        public int $maximumQueuedPayloads = 32,
        public int $maximumQueuedPayloadBytes = 4_194_304,
    ) {
        if ($maximumQueuedPayloads < 1 || $maximumQueuedPayloadBytes < 1) {
            throw new InvalidArgumentException('Login channel limits must be positive.');
        }
    }
}
