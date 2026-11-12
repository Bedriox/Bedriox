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

namespace Bedriox\Server\Map;

use Bedriox\Api\Map\MapImage;
use Bedriox\Api\Map\MapManager;
use Bedriox\Api\Map\MapScale;
use Bedriox\Api\Map\MapView;
use Bedriox\Api\World\BlockFace;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Data\BlockPropertyRegistry;
use Bedriox\Server\Runtime\ManagedWorldRuntime;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use OverflowException;

/** @internal Public map capability bound to one exact loaded dimension handle. */
final readonly class RuntimeMapManager implements MapManager
{
    public function __construct(
        private ApiWorld $handle,
        private ManagedWorldRuntime $runtime,
        private BlockStateRegistry $states,
        private BlockPropertyRegistry $properties,
    ) {
        $this->registry()->attachTerrainSampler(
            $this->handle->getDimensionType(),
            new ChunkMapSurfaceSampler(
                $this->runtime->opened->world,
                $this->states,
                $this->properties,
            ),
        );
    }

    public function createTerrain(ApiPosition $center, MapScale $scale = MapScale::CLOSEST): MapView
    {
        if ($center->world !== null && !$center->world->isSameLoad($this->handle)) {
            throw new InvalidArgumentException('Map center belongs to a different world load.');
        }
        $size = MapRecord::WIDTH * $scale->value;
        $centerX = intdiv((int) floor($center->x) + 64, $size) * $size + intdiv($size, 2) - 64;
        $centerZ = intdiv((int) floor($center->z) + 64, $size) * $size + intdiv($size, 2) - 64;
        $record = $this->registry()->createTerrain(
            $this->handle->getDimensionType(),
            $centerX,
            $centerZ,
            $scale,
        );

        return $this->view($record);
    }

    public function createImage(MapImage $image): MapView
    {
        return $this->view($this->registry()->createImage($this->handle->getDimensionType(), $image));
    }

    public function get(int $id): ?MapView
    {
        $record = $this->registry()->get($id);

        return $record === null ? null : $this->view($record);
    }

    public function placeFrame(
        ApiBlockPosition $position,
        BlockFace $facing,
        MapView $map,
        bool $glowing = false,
    ): void {
        if (!$map instanceof RuntimeMapView || $this->registry()->get($map->getId()) === null) {
            throw new InvalidArgumentException('Map belongs to a different map registry.');
        }
        if (!$this->runtime->simulation->enqueuePluginItemFrame(
            'api',
            new BlockPosition($position->x, $position->y, $position->z),
            $facing,
            $map->getId(),
            $glowing,
        )) {
            throw new OverflowException('The authoritative world action queue rejected the item frame.');
        }
    }

    private function registry(): MapRegistry
    {
        return $this->runtime->opened->world->maps()
            ?? throw new \LogicException('This world does not provide persistent map storage.');
    }

    private function view(MapRecord $record): RuntimeMapView
    {
        return new RuntimeMapView($this->registry(), $record);
    }

}
