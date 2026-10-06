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

namespace Bedriox\Server\Gameplay\Nether;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\Nether\HappyGhastEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\World;
use Closure;
use InvalidArgumentException;
use LogicException;

/** Persistent block-state progression for the twenty-minute dried-ghast hydration cycle. */
final readonly class DriedGhastHydrationRuntime
{
    public const int HYDRATION_STEP_TICKS = 6_000;
    public const int RETRY_TICKS = 20;
    private const int MAXIMUM_LEVEL = 3;

    /** @var array<string, InternalBlockStateId> */
    private array $statesByKey;
    private InternalBlockStateId $air;

    /** @var Closure(EntitySpawnRequest): EntitySpawnOutcome */
    private Closure $spawn;

    /** @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn */
    public function __construct(BlockStateRegistry $states, Closure $spawn)
    {
        $statesByKey = [];
        $air = null;
        foreach ($states->states() as $state) {
            $id = $states->internalId($state);
            $statesByKey[$state->canonicalKey()] = $id;
            if ($state->identifier() === 'minecraft:air' && $state->properties() === []) {
                $air = $id;
            }
        }
        $this->statesByKey = $statesByKey;
        $this->air = $air ?? throw new LogicException('Dried-ghast hydration requires the canonical air state.');
        $this->spawn = $spawn;
    }

    public function tick(World $world, BlockStateRegistry $states, BlockPosition $position, bool $submerged): DriedGhastHydrationTickResult
    {
        $currentId = $world->blockStateAt($position->x, $position->y, $position->z);
        $current = $states->state($currentId);
        if ($current->identifier() !== 'minecraft:dried_ghast') {
            return new DriedGhastHydrationTickResult(false);
        }
        $properties = $current->properties();
        $level = $properties['rehydration_level'] ?? null;
        if (!is_int($level) || $level < 0 || $level > self::MAXIMUM_LEVEL) {
            throw new InvalidArgumentException('Dried-ghast rehydration level is invalid.');
        }

        if (!$submerged) {
            if ($level === 0) {
                return new DriedGhastHydrationTickResult(true);
            }
            $this->replaceLevel($world, $position, $current, $level - 1);

            return new DriedGhastHydrationTickResult(
                true,
                true,
                false,
                $level > 1,
                $level > 1 ? self::HYDRATION_STEP_TICKS : 0,
            );
        }

        if ($level < self::MAXIMUM_LEVEL) {
            $this->replaceLevel($world, $position, $current, $level + 1);

            return new DriedGhastHydrationTickResult(true, true, false, true, self::HYDRATION_STEP_TICKS);
        }

        $yaw = match ($properties['minecraft:cardinal_direction'] ?? 'south') {
            'west' => 90.0,
            'north' => 180.0,
            'east' => 270.0,
            default => 0.0,
        };
        $outcome = ($this->spawn)(new EntitySpawnRequest(
            VanillaEntityType::HAPPY_GHAST,
            SpawnCause::TRANSFORMATION,
            $world->metadata->name,
            new Position($position->x + 0.5, $position->y + 0.1, $position->z + 0.5),
            $yaw,
        ));
        if (!$outcome->entity instanceof HappyGhastEntity) {
            return new DriedGhastHydrationTickResult(true, false, false, true, self::RETRY_TICKS);
        }
        $outcome->entity->setBaby(true);
        $world->setBlockState($position->x, $position->y, $position->z, $this->air);

        return new DriedGhastHydrationTickResult(true, true, true);
    }

    private function replaceLevel(World $world, BlockPosition $position, CanonicalBlockState $current, int $level): void
    {
        $properties = $current->properties();
        $properties['rehydration_level'] = $level;
        $replacement = CanonicalBlockState::from($current->identifier(), $properties);
        $replacementId = $this->statesByKey[$replacement->canonicalKey()] ?? null;
        if (!$replacementId instanceof InternalBlockStateId) {
            throw new LogicException('Required dried-ghast hydration state is not registered.');
        }
        $world->setBlockState($position->x, $position->y, $position->z, $replacementId);
    }
}
