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

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;

final readonly class MovePlayer implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $sequence,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public MovementMode $mode,
        public float $deltaX = 0.0,
        public float $deltaY = 0.0,
        public float $deltaZ = 0.0,
        public bool $jumpRequested = false,
        public ?float $headYaw = null,
        public ?bool $sneaking = null,
        public ?bool $sprinting = null,
        public ClientInputTick $clientTick = new ClientInputTick(0, 0),
        public bool $flying = false,
        public bool $verticalCollision = false,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 96 + strlen($this->session);
    }
}
