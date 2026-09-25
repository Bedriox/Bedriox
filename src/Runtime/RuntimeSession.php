<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Player\PlayerBootstrap;
use LogicException;

final class RuntimeSession
{
    public SessionPhase $phase = SessionPhase::LOGIN;
    public ?BedrockPlayChannel $play = null;
    public bool $joined = false;
    public int $craftingCatalogRevision = 0;
    public ?PlayerBootstrap $bootstrap = null;

    public function __construct(
        public readonly SessionInfo $transport,
        public readonly string $id,
        public readonly UnsignedLong $runtimeEntityId,
        public ?BedrockLoginChannel $login,
    ) {}

    public function promote(BedrockPlayChannel $play): void
    {
        if ($this->phase !== SessionPhase::LOGIN || $this->login === null || $this->play !== null) {
            throw new LogicException('Only a live login session can be promoted once.');
        }
        $this->login = null;
        $this->play = $play;
        $this->phase = SessionPhase::INITIALIZING;
    }

    public function close(): void
    {
        if ($this->phase === SessionPhase::CLOSING) {
            return;
        }
        $this->phase = SessionPhase::CLOSING;
        $this->login?->close();
        $this->play?->close();
        $this->login = null;
        $this->play = null;
        $this->bootstrap = null;
    }
}
