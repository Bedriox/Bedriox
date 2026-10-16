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

namespace Bedriox\Server\Entity\Spawn\Structure;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerType;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\Structure\PlacedStructureMarker;
use Bedriox\Server\World\Generation\Structure\StructureDirection;
use Bedriox\Server\World\Generation\Structure\StructureMarkerType;
use Bedriox\Server\World\Settlement\VillagePointOfInterest;
use Bedriox\Server\World\Settlement\VillagePointOfInterestIndex;
use Bedriox\Server\World\Settlement\VillagePointOfInterestType;
use Closure;
use UnexpectedValueException;

/** Admits bounded, generator-owned village inhabitants exactly once per world. */
final readonly class VillagePopulationRuntime
{
    private const int MAXIMUM_MARKERS_PER_CHUNK = 32;

    /** @var Closure(EntitySpawnRequest): EntitySpawnOutcome */
    private Closure $spawn;

    /** @var Closure(ChunkPosition): array<int, mixed> */
    private Closure $markers;

    /**
     * @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn
     * @param Closure(ChunkPosition): array<int, mixed>         $markers
     */
    public function __construct(
        private VillagePopulationState $state,
        Closure $spawn,
        Closure $markers,
        private ?VillagePopulationStateRepository $repository = null,
        private ?VillagePointOfInterestIndex $pointsOfInterest = null,
    ) {
        $this->spawn = $spawn;
        $this->markers = $markers;
    }

    public function populateFinalizedChunk(string $worldName, ChunkPosition $chunk): int
    {
        $markers = ($this->markers)($chunk);
        if (!array_is_list($markers) || count($markers) > self::MAXIMUM_MARKERS_PER_CHUNK) {
            throw new UnexpectedValueException('Village population markers must be a bounded list.');
        }

        if ($this->pointsOfInterest !== null) {
            foreach ($markers as $marker) {
                if (!$marker instanceof PlacedStructureMarker) {
                    throw new UnexpectedValueException('Village population marker is invalid.');
                }
                $point = self::pointOfInterest($marker);
                if ($point !== null) {
                    $this->pointsOfInterest->register($point);
                }
            }
        }

        $spawned = 0;
        foreach ($markers as $marker) {
            if (!$marker instanceof PlacedStructureMarker) {
                throw new UnexpectedValueException('Village population marker is invalid.');
            }
            if ($marker->type !== StructureMarkerType::EntitySpawn) {
                continue;
            }

            $request = $this->spawnRequest($worldName, $marker);
            if ($request === null) {
                continue;
            }
            $claim = self::claim($marker);

            $claimedVillager = null;
            $workstation = null;
            $bed = null;
            $point = null;
            if ($request->type === VanillaEntityType::VILLAGER && $request->variant !== null) {
                $claimedVillager = self::villagerUniqueId($claim);
                $profession = self::profession($marker->attributes['profession'] ?? null);
                if ($profession->canTrade() && $this->pointsOfInterest !== null) {
                    $point = self::workstationPoint($marker->attributes, $profession);
                    $workstation = $point->position;
                    $this->pointsOfInterest->register($point);
                }
                if ($this->pointsOfInterest !== null) {
                    $bed = $this->pointsOfInterest->nearestAvailableBed(
                        new BlockPosition($marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z),
                        48,
                    )?->position;
                }
            }
            if ($this->state->contains($claim)) {
                continue;
            }
            if ($point !== null && $claimedVillager !== null) {
                if (!$this->pointsOfInterest->claim($workstation, $claimedVillager)) {
                    continue;
                }
            }
            if ($bed !== null && $claimedVillager !== null
                && !$this->pointsOfInterest?->claim($bed, $claimedVillager)) {
                $this->pointsOfInterest?->releaseForVillager(
                    $claimedVillager,
                    VillagePointOfInterestType::WORKSTATION,
                );
                continue;
            }
            if ($claimedVillager !== null) {
                $request = new EntitySpawnRequest(
                    $request->type,
                    $request->cause,
                    $request->worldName,
                    $request->position,
                    $request->yaw,
                    $request->pitch,
                    $claimedVillager,
                    $request->variant,
                );
            }
            $outcome = ($this->spawn)($request);
            if (!$outcome->succeeded()) {
                if ($claimedVillager !== null) {
                    $this->pointsOfInterest?->releaseForVillager($claimedVillager);
                }
                continue;
            }
            if ($outcome->entity instanceof VillagerEntity && $workstation !== null) {
                $outcome->entity->assignWorkstation($workstation);
            }
            if ($outcome->entity instanceof VillagerEntity && $bed !== null) {
                $outcome->entity->assignBed($bed);
            }
            $this->state->claim($claim);
            $this->repository?->save($this->state);
            ++$spawned;
        }

        return $spawned;
    }

    private static function pointOfInterest(PlacedStructureMarker $marker): ?VillagePointOfInterest
    {
        if ($marker->type !== StructureMarkerType::PointOfInterest) {
            return null;
        }
        $type = match ($marker->attributes['poi_type'] ?? null) {
            'bed' => VillagePointOfInterestType::BED,
            'meeting' => VillagePointOfInterestType::MEETING,
            'workstation' => VillagePointOfInterestType::WORKSTATION,
            default => null,
        };
        if ($type === null) {
            return null;
        }
        $profession = $type === VillagePointOfInterestType::WORKSTATION
            ? self::profession($marker->attributes['profession'] ?? null)
            : null;

        return new VillagePointOfInterest(
            $type,
            new BlockPosition($marker->coordinate->x, $marker->coordinate->y, $marker->coordinate->z),
            $marker->identifier,
            $profession,
        );
    }

    private function spawnRequest(string $worldName, PlacedStructureMarker $marker): ?EntitySpawnRequest
    {
        $attributes = $marker->attributes;
        if (($attributes['marker_version'] ?? null) !== 1
            || !isset($attributes['structure_x'], $attributes['structure_z'])
            || !is_int($attributes['structure_x']) || !is_int($attributes['structure_z'])) {
            throw new UnexpectedValueException('Village population marker metadata is malformed.');
        }

        $position = new Position(
            $marker->coordinate->x + 0.5,
            (float) $marker->coordinate->y,
            $marker->coordinate->z + 0.5,
        );
        $yaw = self::yaw($marker->direction);

        if ($marker->identifier === VanillaEntityType::IRON_GOLEM->value) {
            return new EntitySpawnRequest(
                VanillaEntityType::IRON_GOLEM,
                SpawnCause::STRUCTURE,
                $worldName,
                $position,
                $yaw,
            );
        }
        if ($marker->identifier !== VanillaEntityType::VILLAGER->value) {
            return null;
        }

        $profession = self::profession($attributes['profession'] ?? null);
        $type = self::villagerType($attributes['villager_type'] ?? null);
        if ($profession->canTrade()) {
            self::workstationPoint($attributes, $profession);
        }

        return new EntitySpawnRequest(
            VanillaEntityType::VILLAGER,
            SpawnCause::STRUCTURE,
            $worldName,
            $position,
            $yaw,
            variant: strtolower($profession->name) . ':' . strtolower($type->name),
        );
    }

    /** @param array<string, bool|int|string> $attributes */
    private static function workstationPoint(
        array $attributes,
        VillagerProfession $profession,
    ): VillagePointOfInterest {
        foreach (['workstation_x', 'workstation_y', 'workstation_z'] as $coordinate) {
            if (!isset($attributes[$coordinate]) || !is_int($attributes[$coordinate])) {
                throw new UnexpectedValueException('Employed village marker is missing its workstation coordinate.');
            }
        }
        $identifier = $attributes['workstation'] ?? null;
        if (!is_string($identifier) || preg_match('/^minecraft:[a-z0-9_]+$/D', $identifier) !== 1) {
            throw new UnexpectedValueException('Employed village marker has an invalid workstation identity.');
        }

        return new VillagePointOfInterest(
            VillagePointOfInterestType::WORKSTATION,
            new BlockPosition(
                $attributes['workstation_x'],
                $attributes['workstation_y'],
                $attributes['workstation_z'],
            ),
            $identifier,
            $profession,
        );
    }

    private static function profession(mixed $value): VillagerProfession
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Village marker profession is invalid.');
        }

        return match ($value) {
            'farmer' => VillagerProfession::FARMER,
            'fisherman' => VillagerProfession::FISHERMAN,
            'shepherd' => VillagerProfession::SHEPHERD,
            'fletcher' => VillagerProfession::FLETCHER,
            'librarian' => VillagerProfession::LIBRARIAN,
            'cartographer' => VillagerProfession::CARTOGRAPHER,
            'cleric' => VillagerProfession::CLERIC,
            'armorer' => VillagerProfession::ARMORER,
            'weaponsmith' => VillagerProfession::WEAPONSMITH,
            'toolsmith' => VillagerProfession::TOOLSMITH,
            'butcher' => VillagerProfession::BUTCHER,
            'leatherworker' => VillagerProfession::LEATHERWORKER,
            'mason' => VillagerProfession::MASON,
            'unemployed' => VillagerProfession::UNEMPLOYED,
            'nitwit' => VillagerProfession::NITWIT,
            default => throw new UnexpectedValueException('Village marker profession is unsupported.'),
        };
    }

    private static function villagerType(mixed $value): VillagerType
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Village marker appearance is invalid.');
        }

        return match ($value) {
            'plains' => VillagerType::PLAINS,
            'desert' => VillagerType::DESERT,
            'jungle' => VillagerType::JUNGLE,
            'savanna' => VillagerType::SAVANNA,
            'snow' => VillagerType::SNOW,
            'swamp' => VillagerType::SWAMP,
            'taiga' => VillagerType::TAIGA,
            default => throw new UnexpectedValueException('Village marker appearance is unsupported.'),
        };
    }

    private static function claim(PlacedStructureMarker $marker): string
    {
        $identity = implode(':', [
            $marker->attributes['structure_x'],
            $marker->attributes['structure_z'],
            $marker->key,
            $marker->identifier,
            $marker->coordinate->x,
            $marker->coordinate->y,
            $marker->coordinate->z,
        ]);

        return 'village:' . hash('sha256', $identity);
    }

    private static function villagerUniqueId(string $claim): string
    {
        $bytes = hex2bin(substr($claim, strlen('village:'), 32));
        if ($bytes === false) {
            throw new UnexpectedValueException('Village population claim cannot identify a villager.');
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private static function yaw(StructureDirection $direction): float
    {
        return match ($direction) {
            StructureDirection::South => 0.0,
            StructureDirection::West => 90.0,
            StructureDirection::North => 180.0,
            StructureDirection::East => 270.0,
        };
    }
}
