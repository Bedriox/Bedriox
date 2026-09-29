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

use Bedriox\Api\World\Particle\Particle;
use Bedriox\Server\Simulation\Position;

final readonly class SpawnPluginParticle implements WorldCommand
{
    /** @param list<string>|null $targetIdentities */
    public function __construct(
        public string $plugin,
        public Position $position,
        public Particle $particle,
        public ?array $targetIdentities,
    ) {}

    public function sessionId(): string
    {
        return 'plugin:' . $this->plugin;
    }

    public function estimatedBytes(): int
    {
        $targetBytes = array_sum(array_map('strlen', $this->targetIdentities ?? []));

        return 96 + strlen($this->plugin) + $this->particle->estimatedBytes()
            + $targetBytes + 16 * count($this->targetIdentities ?? []);
    }
}
