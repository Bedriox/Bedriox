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

namespace Bedriox\Server\World\Generation\Structure\End;

use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureCoordinate;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;

final readonly class EndStructureLocation
{
    public function __construct(
        public EndStructurePlan $plan,
        public int $cityBaseY,
        public BlockPosition $cityTeleport,
        public ?BlockPosition $shipTeleport = null,
    ) {}

    /** @return list<PlacedStructureMarker> deterministic intent for later population passes */
    public function markers(): array
    {
        $markers = [new PlacedStructureMarker(
            $this->plan->id . ':city_shulker',
            StructureMarkerType::EntitySpawn,
            'minecraft:shulker',
            new StructureCoordinate($this->plan->centerX, $this->cityBaseY + 14, $this->plan->centerZ),
            $this->plan->alongX ? StructureDirection::East : StructureDirection::South,
            ['structure' => 'end_city'],
        )];
        if ($this->shipTeleport !== null) {
            $shipX = $this->plan->centerX + ($this->plan->alongX ? 34 : 62);
            $shipZ = $this->plan->centerZ + ($this->plan->alongX ? 62 : 34);
            $markers[] = new PlacedStructureMarker(
                $this->plan->id . ':ship_loot',
                StructureMarkerType::LootContainer,
                'bedriox:end_ship',
                new StructureCoordinate(
                    $shipX + ($this->plan->alongX ? -3 : 0),
                    $this->cityBaseY + 20,
                    $shipZ + ($this->plan->alongX ? 0 : -3),
                ),
                $this->plan->alongX ? StructureDirection::East : StructureDirection::South,
                ['structure' => 'end_ship', 'contains_elytra' => true],
            );
            $markers[] = new PlacedStructureMarker(
                $this->plan->id . ':ship_shulker',
                StructureMarkerType::EntitySpawn,
                'minecraft:shulker',
                new StructureCoordinate($shipX, $this->cityBaseY + 21, $shipZ),
                $this->plan->alongX ? StructureDirection::East : StructureDirection::South,
                ['structure' => 'end_ship'],
            );
        }

        return $markers;
    }
}
