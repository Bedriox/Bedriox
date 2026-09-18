<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication\Discovery;

use InvalidArgumentException;

final readonly class DiscoveryLimits
{
    public function __construct(
        public int $maximumDiscoveryBytes = 65536,
        public int $maximumOpenIdBytes = 65536,
        public int $maximumJwksBytes = 262144,
        public int $maximumJsonDepth = 8,
        public int $maximumJsonTokens = 4096,
        public int $maximumJwkCount = 64,
        public int $refreshTtlSeconds = 3600,
        public int $hardStaleSeconds = 86400,
        public int $unknownKidRefreshIntervalSeconds = 60,
        public int $initialFailureRetrySeconds = 5,
        public int $maximumFailureRetrySeconds = 300,
    ) {
        if ($maximumDiscoveryBytes < 2 || $maximumOpenIdBytes < 2 || $maximumJwksBytes < 2
            || $maximumJsonDepth < 2 || $maximumJsonDepth > 8 || $maximumJsonTokens < 1
            || $maximumJwkCount < 1 || $maximumJwkCount > 64 || $refreshTtlSeconds < 1 || $refreshTtlSeconds > 604800
            || $hardStaleSeconds < $refreshTtlSeconds || $hardStaleSeconds > 2592000
            || $unknownKidRefreshIntervalSeconds < 1 || $unknownKidRefreshIntervalSeconds > 3600
            || $initialFailureRetrySeconds < 1 || $maximumFailureRetrySeconds < $initialFailureRetrySeconds
            || $maximumFailureRetrySeconds > 3600) {
            throw new InvalidArgumentException('Discovery limits are outside their supported range.');
        }
    }
}
