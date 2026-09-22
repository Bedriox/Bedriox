<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Api\Player\GameMode;

/** Server-authoritative request to change one connected player's gameplay mode. */
final readonly class ChangeGameMode implements WorldCommand
{
    public function __construct(public string $session, public GameMode $gameMode) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 48 + strlen($this->session);
    }
}
