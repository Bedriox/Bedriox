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

namespace Bedriox\Server\Simulation;

use InvalidArgumentException;

final readonly class SimulationLimits
{
    public function __construct(
        public int $ticksPerSecond = 20,
        public int $maximumPlayers = 100,
        public int $maximumQueuedCommands = 4096,
        public int $maximumQueuedBytes = 4_194_304,
        public int $maximumQueuedLifecycleCommands = 256,
        public int $maximumQueuedLifecycleBytes = 65_536,
        public int $maximumCommandsPerTick = 2048,
        public float $maximumCoordinate = 30_000_000.0,
        public float $maximumMovementPerTick = 12.0,
        public int $maximumMovementCreditTicks = 5,
        public float $flatGroundY = 64.0,
        public float $flatGroundTolerance = 0.001,
        public int $jumpAuthorizationTicks = 2,
        public int $maximumChatCharacters = 256,
        public int $maximumChatBytes = 1024,
        public int $chatBucketCapacity = 4,
        public int $chatRefillTicks = 20,
    ) {
        if (
            $this->ticksPerSecond < 1
            || $this->maximumPlayers < 1
            || $this->maximumQueuedCommands < 1
            || $this->maximumQueuedBytes < 1
            || $this->maximumQueuedLifecycleCommands < $this->maximumPlayers
            || $this->maximumPlayers > intdiv($this->maximumQueuedLifecycleBytes, 144)
            || $this->maximumCommandsPerTick < 1
            || !is_finite($this->maximumCoordinate)
            || $this->maximumCoordinate <= 0.0
            || !$this->validPositiveFloat($this->maximumMovementPerTick)
            || $this->maximumMovementCreditTicks < 1
            || !is_finite($this->flatGroundY)
            || !$this->validPositiveFloat($this->flatGroundTolerance)
            || $this->jumpAuthorizationTicks < 1
            || $this->maximumChatCharacters < 1
            || $this->maximumChatBytes < 1
            || $this->chatBucketCapacity < 1
            || $this->chatRefillTicks < 1
        ) {
            throw new InvalidArgumentException('Simulation limits must be positive and finite.');
        }
    }

    private function validPositiveFloat(float $value): bool
    {
        return is_finite($value) && $value > 0.0;
    }
}
