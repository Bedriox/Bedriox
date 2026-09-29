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

namespace Bedriox\Api\World\Particle;

use Bedriox\Api\World\BlockFace;
use InvalidArgumentException;

final readonly class BlockParticle implements Particle
{
    public function __construct(
        public BlockParticleType $type,
        public ParticleBlockState $block,
        public ?BlockFace $face = null,
    ) {
        if (($type === BlockParticleType::PUNCH) !== ($face !== null)) {
            throw new InvalidArgumentException('Only block-punch particles require a block face.');
        }
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->block->canonicalKey());
    }
}
