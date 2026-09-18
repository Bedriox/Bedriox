<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

use InvalidArgumentException;

final readonly class FullAuthenticationLimits
{
    public function __construct(
        public int $maximumTokenBytes = 131072,
        public int $maximumHeaderBytes = 4096,
        public int $maximumPayloadBytes = 65536,
        public int $maximumSignatureBytes = 512,
        public int $maximumJsonDepth = 16,
        public int $maximumJwkCount = 64,
        public int $maximumKidBytes = 128,
        public int $maximumRsaModulusBytes = 512,
        public int $maximumRsaExponentBytes = 8,
        public int $maximumClockSkewSeconds = 60,
        public int $maximumSubjectBytes = 256,
        public int $maximumDisplayNameBytes = 128,
        public int $maximumXuidBytes = 32,
        public int $maximumMinecraftIdBytes = 128,
        public int $maximumCpkBytes = 2048,
    ) {
        if ($maximumTokenBytes < 3 || $maximumHeaderBytes < 2 || $maximumPayloadBytes < 2
            || $maximumSignatureBytes < 256 || $maximumJsonDepth < 2 || $maximumJsonDepth > 64
            || $maximumJwkCount < 1 || $maximumJwkCount > 64 || $maximumKidBytes < 1 || $maximumRsaModulusBytes < 256
            || $maximumRsaExponentBytes < 1 || $maximumClockSkewSeconds < 0 || $maximumClockSkewSeconds > 300
            || $maximumSubjectBytes < 1 || $maximumDisplayNameBytes < 1 || $maximumXuidBytes < 1
            || $maximumMinecraftIdBytes < 1 || $maximumCpkBytes < 1) {
            throw new InvalidArgumentException('FULL authentication limits are outside their supported range.');
        }
    }
}
