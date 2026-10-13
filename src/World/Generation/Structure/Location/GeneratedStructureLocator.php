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

use Bedriox\Api\World\DimensionType;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\EndWorldGenerator;
use Bedriox\Server\World\Generation\Structure\End\EndStructureKind;
use Bedriox\Server\World\Generation\Structure\Nether\NetherStructureKind;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureFamily;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureInspection;
use Bedriox\Server\World\NetherWorldGenerator;

/** Resolves only known public structure names through the exact generation qualification hooks. */
final readonly class GeneratedStructureLocator
{
    private DefaultWorldGenerator $overworld;
    private NetherWorldGenerator $nether;
    private EndWorldGenerator $end;

    public function __construct(int $seed, BlockStateRegistry $states)
    {
        $this->overworld = new DefaultWorldGenerator($seed, $states);
        $this->nether = new NetherWorldGenerator($seed, $states);
        $this->end = new EndWorldGenerator($seed, $states);
    }

    public function locate(DimensionType $dimension, string $name, int $x, int $z): ?StructureLocation
    {
        $name = strtolower($name);

        return match ($dimension) {
            DimensionType::OVERWORLD => $this->locateOverworld($name, $x, $z),
            DimensionType::NETHER => $this->locateNether($name, $x, $z),
            DimensionType::END => $this->locateEnd($name, $x, $z),
        };
    }

    private function locateOverworld(string $name, int $x, int $z): ?StructureLocation
    {
        $families = match ($name) {
            'stronghold' => [OverworldStructureFamily::Stronghold],
            'village' => [OverworldStructureFamily::Village],
            default => [],
        };
        $locations = [];
        foreach ($families as $family) {
            $candidate = $this->overworld->locateStructure($family, $x, $z, 32);
            if ($candidate !== null) {
                $inspection = OverworldStructureInspection::position($candidate);
                $locations[] = new StructureLocation(
                    $candidate->start->family->value,
                    $candidate->start->variant,
                    DimensionType::OVERWORLD,
                    $inspection->x,
                    $inspection->y,
                    $inspection->z,
                );
            }
        }

        return self::nearest($locations, $x, $z);
    }

    private function locateNether(string $name, int $x, int $z): ?StructureLocation
    {
        $kinds = match ($name) {
            'fortress' => [NetherStructureKind::Fortress],
            'bastionremnant', 'bastion_remnant' => [
                NetherStructureKind::BastionBridge,
                NetherStructureKind::BastionHoglinStable,
                NetherStructureKind::BastionHousing,
                NetherStructureKind::BastionTreasure,
            ],
            default => [],
        };
        $locations = [];
        foreach ($kinds as $kind) {
            $candidate = $this->nether->locateStructureLocation($kind, $x, $z, 32);
            if ($candidate === null) {
                continue;
            }
            $identifier = $kind->isBastion() ? 'bastion_remnant' : match ($kind) {
                NetherStructureKind::Fortress => 'nether_fortress',
                default => $kind->value,
            };
            $variant = $kind->isBastion() ? substr($kind->value, strlen('bastion_')) : 'standard';
            $locations[] = new StructureLocation(
                $identifier,
                $variant,
                DimensionType::NETHER,
                $candidate->safeTeleport->x,
                $candidate->safeTeleport->y,
                $candidate->safeTeleport->z,
            );
        }

        return self::nearest($locations, $x, $z);
    }

    private function locateEnd(string $name, int $x, int $z): ?StructureLocation
    {
        if ($name !== 'endcity' && $name !== 'end_city') {
            return null;
        }
        $candidate = $this->end->locateStructureLocation(EndStructureKind::City, $x, $z, 32);
        if ($candidate === null) {
            return null;
        }

        return new StructureLocation(
            'end_city',
            'standard',
            DimensionType::END,
            $candidate->cityTeleport->x,
            $candidate->cityTeleport->y,
            $candidate->cityTeleport->z,
        );
    }

    /** @param list<StructureLocation> $locations */
    private static function nearest(array $locations, int $x, int $z): ?StructureLocation
    {
        usort($locations, static function (StructureLocation $left, StructureLocation $right) use ($x, $z): int {
            $leftDistance = ($left->x - $x) ** 2 + ($left->z - $z) ** 2;
            $rightDistance = ($right->x - $x) ** 2 + ($right->z - $z) ** 2;

            return $leftDistance <=> $rightDistance
                ?: [$left->identifier, $left->variant, $left->x, $left->z]
                    <=> [$right->identifier, $right->variant, $right->x, $right->z];
        });

        return $locations[0] ?? null;
    }
}
