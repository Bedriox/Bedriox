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

namespace Bedriox\Api\World\Rail;

use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use InvalidArgumentException;

/** Immutable public view of one canonical rail block. */
final readonly class RailSegment
{
    public function __construct(
        public BlockPosition $position,
        public string $identifier,
        public RailShape $shape,
    ) {
        if (!BlockTagRegistry::contains(BlockTag::RAIL, $identifier)) {
            throw new InvalidArgumentException('Rail segment identifier must belong to the rail block family.');
        }
        if ($shape->isCurved() && $identifier !== 'minecraft:rail') {
            throw new InvalidArgumentException('Only ordinary rails support curved shapes.');
        }
    }

    /** @return array{RailEndpoint, RailEndpoint} */
    public function endpoints(): array
    {
        return $this->shape->endpoints();
    }
}
