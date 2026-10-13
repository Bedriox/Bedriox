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

final readonly class StructurePlacement
{
    /**
     * @param list<PlacedStructureBlock>     $blocks
     * @param list<PlacedStructureConnector> $connectors
     * @param list<PlacedStructureMarker>    $markers
     */
    public function __construct(
        public int $width,
        public int $height,
        public int $depth,
        public array $blocks,
        public array $connectors,
        public array $markers = [],
    ) {}

    public function connector(string $key): PlacedStructureConnector
    {
        foreach ($this->connectors as $connector) {
            if ($connector->key === $key) {
                return $connector;
            }
        }

        throw new InvalidArgumentException("Structure placement does not contain connector $key.");
    }
}
