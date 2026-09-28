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

namespace Bedriox\Api\World;

use InvalidArgumentException;

/** Immutable snapshot of one loaded world's public state. */
final readonly class WorldInfo
{
    public function __construct(
        public World $world,
        public string $displayName,
        public string $generator,
        public int $seed,
        public WorldDifficulty $difficulty,
        public int $time,
        public Position $spawn,
        public int $playerCount,
    ) {
        if ($displayName === '' || strlen($displayName) > 128 || preg_match('//u', $displayName) !== 1) {
            throw new InvalidArgumentException('World display name must be bounded UTF-8.');
        }
        if ($generator === '' || strlen($generator) > 128 || $playerCount < 0) {
            throw new InvalidArgumentException('World information contains invalid bounded values.');
        }
        $spawn->validateResolved();
        if (!$spawn->world?->isSameLoad($world)) {
            throw new InvalidArgumentException('World spawn must resolve to the described world generation.');
        }
    }
}
