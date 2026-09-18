<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

use InvalidArgumentException;

final readonly class CrashPlayer
{
    public function __construct(
        public string $name,
        public string $uuid,
        public string $xuid,
        public string $remoteAddress,
        public string $platform,
        public string $sessionPhase,
    ) {
        foreach ([$this->name, $this->uuid, $this->xuid, $this->remoteAddress, $this->platform, $this->sessionPhase] as $value) {
            if (strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('Crash player field is invalid.');
            }
        }
    }
}
