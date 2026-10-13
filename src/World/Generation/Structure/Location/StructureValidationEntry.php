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

namespace Bedriox\Server\World\Generation\Structure\Location;

/** One machine- and human-readable validation target. */
final readonly class StructureValidationEntry
{
    public function __construct(
        public StructureLocation $location,
        public string $condition = 'terrain_generation',
    ) {
        if ($condition === '' || strlen($condition) > 64) {
            throw new \InvalidArgumentException('Structure validation condition must be bounded text.');
        }
    }

    /** @return array{identifier:string, variant:string, dimension:string, x:int, y:int, z:int, condition:string, teleport:string} */
    public function toArray(): array
    {
        return [
            'identifier' => $this->location->identifier,
            'variant' => $this->location->variant,
            'dimension' => $this->location->dimension->value,
            'x' => $this->location->x,
            'y' => $this->location->y,
            'z' => $this->location->z,
            'condition' => $this->condition,
            'teleport' => $this->location->teleportCommand(),
        ];
    }
}
