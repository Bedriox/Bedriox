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

namespace Bedriox\Server\World\Provider;

use Bedriox\Data\PersistentBlockStateRegistry;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Closure;

final class LevelDbWorldProviderFactory implements WorldProviderFactory
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|Closure(): int $clock */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function open(
        string $worldPath,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider {
        return LevelDbWorldProvider::open($worldPath, $blockStates, $persistentBlockStates);
    }

    public function create(
        string $worldPath,
        WorldData $worldData,
        BlockStateRegistry $blockStates,
        PersistentBlockStateRegistry $persistentBlockStates,
    ): WritableWorldProvider {
        return LevelDbWorldProvider::create(
            $worldPath,
            $worldData,
            $blockStates,
            $persistentBlockStates,
            createdAt: ($this->clock)(),
        );
    }
}
