<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

/** Authenticated identity projected into the authoritative player domain. */
final readonly class PlayerIdentity
{
    public function __construct(
        public string $uuid,
        public string $displayName,
        public string $xuid = '',
    ) {}
}
