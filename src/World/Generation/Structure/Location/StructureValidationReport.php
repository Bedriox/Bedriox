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
use Bedriox\Server\Gameplay\End\EndGatewayPlanner;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\DefaultWorldGenerator;
use Bedriox\Server\World\EndWorldGenerator;
use Bedriox\Server\World\Generation\Structure\End\EndInfrastructureCatalog;
use Bedriox\Server\World\Generation\Structure\End\EndStructureKind;
use Bedriox\Server\World\Generation\Structure\Nether\NetherStructureKind;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureCatalog;
use Bedriox\Server\World\Generation\Structure\Overworld\OverworldStructureInspection;
use Bedriox\Server\World\NetherWorldGenerator;

/** Exhaustive deterministic coordinates for validating every implemented family and variant. */
final readonly class StructureValidationReport
{
    private DefaultWorldGenerator $overworld;
    private NetherWorldGenerator $nether;
    private EndWorldGenerator $end;

    public function __construct(private int $seed, BlockStateRegistry $states)
    {
        $this->overworld = new DefaultWorldGenerator($seed, $states);
        $this->nether = new NetherWorldGenerator($seed, $states);
        $this->end = new EndWorldGenerator($seed, $states);
    }

    /** @return list<StructureValidationEntry> */
    public function entries(): array
    {
        $entries = $this->overworldEntries();
        array_push($entries, ...$this->netherEntries(), ...$this->endEntries());
        usort($entries, static fn(StructureValidationEntry $left, StructureValidationEntry $right): int =>
            [$left->location->dimension->value, $left->location->identifier, $left->location->variant]
                <=> [$right->location->dimension->value, $right->location->identifier, $right->location->variant]);

        return $entries;
    }

    /** @return list<StructureValidationEntry> */
    private function overworldEntries(): array
    {
        $entries = [];
        foreach (OverworldStructureCatalog::all() as $definition) {
            $locations = $this->overworld->locateStructureVariants($definition->family, 0, 0, 64);
            foreach ($definition->variants as $variant) {
                $found = $locations[$variant] ?? null;
                if ($found === null) {
                    throw new \RuntimeException("No eligible {$definition->family->value}/{$variant} validation start was found.");
                }
                $inspection = OverworldStructureInspection::position($found);
                $entries[] = new StructureValidationEntry(new StructureLocation(
                    $definition->family->value,
                    $variant,
                    DimensionType::OVERWORLD,
                    $inspection->x,
                    $inspection->y,
                    $inspection->z,
                ));
            }
        }

        return $entries;
    }

    /** @return list<StructureValidationEntry> */
    private function netherEntries(): array
    {
        $targets = [
            ['nether_fortress', 'standard', NetherStructureKind::Fortress],
            ['bastion_remnant', 'bridge', NetherStructureKind::BastionBridge],
            ['bastion_remnant', 'hoglin_stable', NetherStructureKind::BastionHoglinStable],
            ['bastion_remnant', 'housing', NetherStructureKind::BastionHousing],
            ['bastion_remnant', 'treasure', NetherStructureKind::BastionTreasure],
        ];
        $entries = [];
        foreach ($targets as [$identifier, $variant, $kind]) {
            $found = $this->nether->locateStructureLocation($kind, 0, 0, 64);
            if ($found === null) {
                throw new \RuntimeException("No eligible {$identifier}/{$variant} validation start was found.");
            }
            $entries[] = new StructureValidationEntry(new StructureLocation(
                $identifier,
                $variant,
                DimensionType::NETHER,
                $found->safeTeleport->x,
                $found->safeTeleport->y,
                $found->safeTeleport->z,
            ));
        }
        return $entries;
    }

    /** @return list<StructureValidationEntry> */
    private function endEntries(): array
    {
        $city = $this->end->locateStructureLocation(EndStructureKind::City, 0, 0, 64)
            ?? throw new \RuntimeException('No eligible end_city validation start was found.');
        $ship = $this->end->locateStructureLocation(EndStructureKind::CityWithShip, 0, 0, 64)
            ?? throw new \RuntimeException('No eligible end_ship validation start was found.');
        if ($ship->shipTeleport === null) {
            throw new \LogicException('A ship-bearing End city did not expose its ship validation coordinate.');
        }
        $entries = [
            new StructureValidationEntry(new StructureLocation(
                'end_city',
                'standard',
                DimensionType::END,
                $city->cityTeleport->x,
                $city->cityTeleport->y,
                $city->cityTeleport->z,
            )),
            new StructureValidationEntry(new StructureLocation(
                'end_ship',
                'standard',
                DimensionType::END,
                $ship->shipTeleport->x,
                $ship->shipTeleport->y,
                $ship->shipTeleport->z,
            )),
        ];
        $seenInfrastructure = [];
        foreach (EndInfrastructureCatalog::landmarks() as $landmark) {
            $key = $landmark->kind . ':' . $landmark->variant;
            if (isset($seenInfrastructure[$key])) {
                continue;
            }
            $seenInfrastructure[$key] = true;
            $entries[] = new StructureValidationEntry(new StructureLocation(
                $landmark->kind,
                $landmark->variant,
                DimensionType::END,
                $landmark->x,
                $landmark->y,
                $landmark->z,
            ));
        }
        $gateway = EndGatewayPlanner::inner(EndGatewayPlanner::order($this->seed)[0]);
        $entries[] = new StructureValidationEntry(new StructureLocation(
            'end_gateway',
            'standard',
            DimensionType::END,
            $gateway->x,
            $gateway->y,
            $gateway->z,
        ), 'dragon_victory');

        return $entries;
    }
}
