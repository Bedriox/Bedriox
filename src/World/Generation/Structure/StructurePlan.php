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

use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use InvalidArgumentException;

final readonly class StructurePlan
{
    public StructureBoundingBox $bounds;

    public function __construct(
        public string $id,
        public StructureCoordinate $start,
        public StructurePieceGraph $graph,
    ) {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Structure plan ID must be a bounded lowercase identifier.');
        }
        $pieces = $graph->pieces();
        $bounds = $pieces[0]->bounds;
        foreach (array_slice($pieces, 1) as $piece) {
            $bounds = $bounds->union($piece->bounds);
        }
        $this->bounds = $bounds;
    }

    public function replay(MutableChunkBuilder $builder, GenerationBlockPalette $palette): void
    {
        if (!$this->bounds->intersectsChunk($builder->position)) {
            return;
        }
        $minimumX = $builder->position->x * 16;
        $minimumZ = $builder->position->z * 16;
        foreach ($this->graph->pieces() as $piece) {
            if (!$piece->bounds->intersectsChunk($builder->position)) {
                continue;
            }
            foreach ($piece->placement->blocks as $block) {
                if ($block->coordinate->x < $minimumX || $block->coordinate->x > $minimumX + 15
                    || $block->coordinate->z < $minimumZ || $block->coordinate->z > $minimumZ + 15) {
                    continue;
                }
                $processed = $piece->processed($block);
                if ($processed === null) {
                    continue;
                }
                $builder->setWorld(
                    $processed->coordinate->x,
                    $processed->coordinate->y,
                    $processed->coordinate->z,
                    $palette->canonical($processed->state),
                );
            }
        }
    }

    /** @return list<PlacedStructureMarker> */
    public function markers(): array
    {
        $markers = [];
        foreach ($this->graph->pieces() as $piece) {
            array_push($markers, ...$piece->placement->markers);
        }

        return $markers;
    }

    /** @return list<PlacedStructureMarker> */
    public function markersInChunk(\Bedriox\Server\World\ChunkPosition $position): array
    {
        $minimumX = $position->x * 16;
        $minimumZ = $position->z * 16;

        return array_values(array_filter(
            $this->markers(),
            static fn(PlacedStructureMarker $marker): bool => $marker->coordinate->x >= $minimumX
                && $marker->coordinate->x <= $minimumX + 15
                && $marker->coordinate->z >= $minimumZ
                && $marker->coordinate->z <= $minimumZ + 15,
        ));
    }
}
