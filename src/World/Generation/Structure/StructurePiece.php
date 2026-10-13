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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

final readonly class StructurePiece
{
    public StructureBoundingBox $bounds;

    /** @param list<StructureProcessor> $processors */
    public function __construct(
        public string $id,
        public StructurePlacement $placement,
        public array $processors = [],
    ) {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Structure piece ID must be a bounded lowercase identifier.');
        }
        $this->bounds = StructureBoundingBox::fromPlacement($placement);
    }

    public function processed(PlacedStructureBlock $block): ?PlacedStructureBlock
    {
        foreach ($this->processors as $processor) {
            $block = $processor->process($block);
            if ($block === null) {
                return null;
            }
        }

        return $block;
    }
}
