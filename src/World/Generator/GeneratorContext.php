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

namespace Bedriox\Server\World\Generator;

use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;

final readonly class GeneratorContext
{
    public function __construct(
        public int $seed,
        public string $dimension,
        public GeneratorOptions $options,
        public BlockStateRegistry $blockStates,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $dimension) !== 1) {
            throw new InvalidArgumentException('Generator dimension must be a bounded namespaced identifier.');
        }
    }
}
