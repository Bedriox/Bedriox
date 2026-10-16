<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\DimensionType;
use Bedriox\Protocol\Packet\PlayerSkin;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Security\ProtocolViolationTracker;
use LogicException;

final class RuntimeSession
{
    public SessionPhase $phase = SessionPhase::LOGIN;
    public ?BedrockPlayChannel $play = null;
    public bool $joined = false;
    public int $craftingCatalogRevision = 0;
    public ?PlayerBootstrap $bootstrap = null;
    public ?PlayerSkin $playerSkin = null;
    public string $worldId;
    public DimensionType $dimension;
    public readonly ProtocolViolationTracker $protocolViolations;

    public function __construct(
        public readonly SessionInfo $transport,
        public readonly string $id,
        public readonly UnsignedLong $runtimeEntityId,
        public ?BedrockLoginChannel $login,
        string $worldId = 'world',
        DimensionType $dimension = DimensionType::OVERWORLD,
        ?ProtocolViolationTracker $protocolViolations = null,
    ) {
        $this->worldId = WorldRuntimeManager::canonicalId($worldId);
        $this->dimension = $dimension;
        $this->protocolViolations = $protocolViolations ?? new ProtocolViolationTracker();
    }

    public function promote(BedrockPlayChannel $play): void
    {
        if ($this->phase !== SessionPhase::LOGIN || $this->login === null || $this->play !== null) {
            throw new LogicException('Only a live login session can be promoted once.');
        }
        $this->playerSkin = PlayerSkin::fromVerifiedClientData($play->login()->clientData);
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
        $this->playerSkin = null;
    }
}
