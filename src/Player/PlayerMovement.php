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

namespace Bedriox\Server\Player;

use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;

/** Mutable movement state owned exclusively by the authoritative world tick. */
final class PlayerMovement
{
    public float $yaw = 0.0;
    public float $headYaw = 0.0;
    public float $pitch = 0.0;
    public MovementMode $mode = MovementMode::STOPPED;
    public bool $sneaking = false;
    public bool $sprinting = false;
    public int $sequence = -1;
    public float $velocityX = 0.0;
    public float $verticalVelocity = 0.0;
    public float $velocityZ = 0.0;
    public ClientInputTick $clientTick;
    public float $distanceThisTick = 0.0;
    public int $jumpAuthorizedUntilTick = -1;
    public float $fallDistance = 0.0;

    public function __construct(
        public Position $position,
        public VerticalState $verticalState,
        public int $lastTick,
        public int $budgetTick,
    ) {
        $this->clientTick = ClientInputTick::fromInt(0);
    }
}
