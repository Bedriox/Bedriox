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

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Simulation\PlayerTeleportDecision;
use Bedriox\Server\Simulation\Position;

/** @internal Main-thread ownership ticket for one staged world or dimension transfer. */
final readonly class PendingPlayerWorldTransfer
{
    public function __construct(
        public string $identity,
        public string $sessionId,
        public string $sourceWorldId,
        public WorldDimension $sourceDimension,
        public string $targetWorldId,
        public WorldDimension $targetDimension,
        public PlayerTeleportDecision $decision,
        public Position $from,
        public float $fromYaw,
        public float $fromPitch,
        public int $startedNanoseconds,
    ) {}
}
