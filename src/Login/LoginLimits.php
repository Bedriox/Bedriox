<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use InvalidArgumentException;

final readonly class LoginLimits
{
    public function __construct(
        public int $maximumQueuedPackets = 32,
        public int $maximumQueuedInputBytes = 1_048_576,
        public int $maximumPacketBytes = 1_048_576,
        public int $maximumQueuedEffects = 32,
    ) {
        if ($maximumQueuedPackets < 1 || $maximumQueuedInputBytes < 1 || $maximumPacketBytes < 1 || $maximumQueuedEffects < 1) {
            throw new InvalidArgumentException('Login limits must be positive.');
        }
    }
}
