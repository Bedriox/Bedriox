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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\World;
use Closure;

/** Bounded authoritative owner of the central Ender Dragon encounter. */
final class EndEncounterCoordinator
{
    private const int FIRST_VICTORY_EXPERIENCE = 12_000;
    private const int REPEAT_VICTORY_EXPERIENCE = 500;

    private EndEncounterState $state;
    private int $lastSavedRevision = -1;
    private int $phaseLockTicks = 0;
    private int $missingDragonTicks = 0;

    private bool $exitFountainReconciled = false;
    private int $ritualRecoveryGraceTicks;

    /** @var Closure(EntitySpawnRequest): EntitySpawnOutcome */
    private readonly Closure $spawn;

    /** @var null|Closure(): list<EndEncounterTarget> */
    private readonly ?Closure $targets;

    /** @var null|Closure(BlockPosition, InternalBlockStateId): void */
    private readonly ?Closure $setBlock;

    /** @var null|Closure(EnderDragonPhase, EnderDragonPhase): ?EnderDragonPhase */
    private readonly ?Closure $phaseChange;

    /** @var null|Closure(EnderDragonEntity, float): float */
    private readonly ?Closure $healAmount;

    /** @var null|Closure(int, BlockPosition): ?BlockPosition */
    private readonly ?Closure $gatewayPosition;

    /**
     * @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn
     * @param null|Closure(): list<EndEncounterTarget> $targets
     * @param null|Closure(BlockPosition, InternalBlockStateId): void $setBlock
     * @param null|Closure(EnderDragonPhase, EnderDragonPhase): ?EnderDragonPhase $phaseChange
     * @param null|Closure(EnderDragonEntity, float): float $healAmount
     * @param null|Closure(int, BlockPosition): ?BlockPosition $gatewayPosition
     */
    public function __construct(
        private readonly string $worldName,
        private readonly int $seed,
        private readonly EntityRegistry $entities,
        Closure $spawn,
        private readonly ?World $world = null,
        private readonly ?BlockStateRegistry $blocks = null,
        private readonly ?EndEncounterStateRepository $repository = null,
        ?Closure $targets = null,
        ?Closure $setBlock = null,
        ?Closure $phaseChange = null,
        ?Closure $healAmount = null,
        ?Closure $gatewayPosition = null,
    ) {
        $this->spawn = $spawn;
        $this->targets = $targets;
        $this->setBlock = $setBlock;
        $this->phaseChange = $phaseChange;
        $this->healAmount = $healAmount;
        $this->gatewayPosition = $gatewayPosition;
        $this->state = $repository?->load() ?? new EndEncounterState();
        $this->ritualRecoveryGraceTicks = $this->state->respawnStage === EnderDragonRespawnStage::NONE ? 0 : 100;
    }

    public function state(): EndEncounterState
    {
        return $this->state;
    }

    public function firstVictoryExperience(): int
    {
        return self::FIRST_VICTORY_EXPERIENCE;
    }

    public function repeatVictoryExperience(): int
    {
        return self::REPEAT_VICTORY_EXPERIENCE;
    }

    public function confirmDragonDeath(string $uuid): bool
    {
        $next = $this->state->confirmDragonDeath($uuid);
        if ($next === $this->state) {
            return false;
        }
        $this->replace($next);
        $this->save();

        return true;
    }

    /** Claims the durable publication record before non-transactional reward effects are emitted. */
    public function claimVictoryPublication(): bool
    {
        if ($this->state->victoryStage !== EndVictoryStage::PREPARED) {
            return false;
        }
        $this->replace($this->state->withVictoryStage(EndVictoryStage::PUBLICATION_CLAIMED));
        $this->save();

        return true;
    }

    public function completeVictoryPublication(): void
    {
        if ($this->state->victoryStage !== EndVictoryStage::PUBLICATION_CLAIMED) {
            return;
        }
        $this->replace($this->state->withVictoryStage(EndVictoryStage::PUBLISHED));
        $this->save();
    }

    public function requestRespawn(): bool
    {
        if (!$this->canRequestRespawn()) {
            return false;
        }
        $crystals = $this->respawnCrystals();
        if ($crystals === null) {
            return false;
        }
        $this->replace($this->state->beginRespawn(array_map(
            static fn(EndCrystalEntity $crystal): string => $crystal->getUniqueId(),
            $crystals,
        )));
        foreach ($crystals as $crystal) {
            $crystal->configureEncounterState(true, $crystal->showsBase(), new Position(0.0, 128.0, 0.0), true);
        }
        $this->ritualRecoveryGraceTicks = 0;
        $this->save();

        return true;
    }

    public function canRequestRespawn(): bool
    {
        return $this->respawnCrystals() !== null && $this->state->dragonKilled
            && $this->state->victoryStage === EndVictoryStage::PUBLISHED
            && $this->state->respawnStage === EnderDragonRespawnStage::NONE;
    }

    public function requestPhase(EnderDragonPhase $phase): bool
    {
        if ($this->state->dragonKilled || $this->state->respawnStage !== EnderDragonRespawnStage::NONE
            || $phase === EnderDragonPhase::DYING || $this->dragons() === []) {
            return false;
        }
        $this->replace($this->state->withPhase($phase));
        $this->phaseLockTicks = 100;

        return true;
    }

    public function destroyCrystal(int $runtimeId): ?EndCrystalExplosion
    {
        $crystal = $this->entities->getByRuntimeId($runtimeId);
        if (!$crystal instanceof EndCrystalEntity || $crystal->getWorldName() !== $this->worldName
            || !$crystal->isAlive()) {
            return null;
        }
        $position = $crystal->internalPosition();
        $this->entities->remove($runtimeId);
        if (in_array($crystal->getUniqueId(), $this->state->ritualCrystalUuids, true)) {
            $this->clearRitualCrystalBeams();
            $this->replace($this->state->abortRespawn());
            $this->save();
        }

        return new EndCrystalExplosion($position);
    }

    public function tick(int $tick): EndEncounterTickResult
    {
        if ($tick < 0) {
            throw new \InvalidArgumentException('End encounter tick cannot be negative.');
        }
        $dragons = $this->dragons();
        if (!$this->state->initialized) {
            $dragon = $dragons[0] ?? $this->spawnDragon();
            if ($dragon === null) {
                return new EndEncounterTickResult();
            }
            $this->ensureInitialCrystals();
            $this->replace($this->state->withDragon($dragon->getUniqueId()));
            $this->save();

            return new EndEncounterTickResult(true, $dragons === [], false, false, false);
        }

        if (count($dragons) > 1) {
            foreach (array_slice($dragons, 1) as $duplicate) {
                $this->entities->remove($duplicate->getRuntimeId());
            }
            $dragons = [$dragons[0]];
        }

        if ($this->state->respawnStage !== EnderDragonRespawnStage::NONE) {
            return $this->advanceRespawn($tick);
        }

        if ($this->state->victoryStage === EndVictoryStage::DEATH_CONFIRMED) {
            $this->activateExitFountain();
            $gateway = $this->activateNextGateway();
            $this->replace($this->state->prepareVictory($gateway !== null));
            $this->save();

            return new EndEncounterTickResult(true, false, true, $gateway !== null, false, gatewayPosition: $gateway);
        }
        if ($this->state->victoryStage === EndVictoryStage::PREPARED) {
            return new EndEncounterTickResult(false, false, true, false, false);
        }
        if ($this->state->victoryStage === EndVictoryStage::PUBLICATION_CLAIMED) {
            // A crash after the claim must not publish rewards twice. Permanent arena
            // mutations are reconstructed from the prepared state on world startup.
            $this->completeVictoryPublication();
            return new EndEncounterTickResult(true);
        }
        if ($this->state->dragonKilled && $this->state->victoryStage === EndVictoryStage::PUBLISHED) {
            $this->missingDragonTicks = 0;
            $reconciled = false;
            if ($this->state->exitPortalActive && !$this->exitFountainReconciled) {
                $reconciled = $this->activateExitFountain();
            }

            return new EndEncounterTickResult($reconciled);
        }

        $dragon = $this->dragonForState($dragons);
        if ($dragon === null || !$dragon->isAlive()) {
            // Registry absence can mean an unloaded actor. Victory requires an explicit
            // authoritative death report through confirmDragonDeath(). If the arena stays
            // active without the persisted actor, reconcile by replacing it, never by winning.
            if (++$this->missingDragonTicks >= 100) {
                $replacement = $this->spawnDragon();
                if ($replacement !== null) {
                    $this->replace($this->state->withDragon($replacement->getUniqueId()));
                    $this->save();
                    $this->missingDragonTicks = 0;

                    return new EndEncounterTickResult(true, true);
                }
            }
            return new EndEncounterTickResult();
        }
        $this->missingDragonTicks = 0;

        $healed = false;
        $healingCrystal = $dragon->getHealth() < $dragon->getMaximumHealth()
            ? $this->nearestCrystal($dragon->internalPosition())
            : null;
        $this->synchronizeHealingCrystalBeam($dragon, $healingCrystal);
        if ($tick % 10 === 0 && $healingCrystal !== null) {
            $amount = $this->healAmount === null ? 1.0 : ($this->healAmount)($dragon, 1.0);
            $healed = $amount > 0.0 && $dragon->heal($amount) > 0.0;
        }
        $attacks = $this->advanceDragonFlight($dragon, $tick);

        return new EndEncounterTickResult(false, false, false, false, $healed, $attacks);
    }

    public function flush(): void
    {
        $this->save();
    }

    /** @return list<EnderDragonEntity> */
    private function dragons(): array
    {
        return array_values(array_filter(
            $this->entities->all(),
            fn($entity): bool => $entity instanceof EnderDragonEntity && $entity->getWorldName() === $this->worldName,
        ));
    }

    private function spawnDragon(): ?EnderDragonEntity
    {
        $outcome = ($this->spawn)(new EntitySpawnRequest(
            VanillaEntityType::ENDER_DRAGON,
            SpawnCause::STRUCTURE,
            $this->worldName,
            new Position(0.5, 92.0, 0.5),
        ));
        return $outcome->entity instanceof EnderDragonEntity ? $outcome->entity : null;
    }

    /** @param list<EnderDragonEntity> $dragons */
    private function dragonForState(array $dragons): ?EnderDragonEntity
    {
        foreach ($dragons as $dragon) {
            if ($dragon->getUniqueId() === $this->state->dragonUuid) {
                return $dragon;
            }
        }

        return null;
    }

    private function ensureInitialCrystals(): void
    {
        $existing = array_values(array_filter(
            $this->entities->all(),
            fn($entity): bool => $entity instanceof EndCrystalEntity
                && $entity->getWorldName() === $this->worldName
                && $entity->isAlive(),
        ));
        foreach (EndArenaLayout::crystalPositions() as $position) {
            foreach ($existing as $crystal) {
                if ($crystal->internalPosition()->distanceTo($position) <= 0.8) {
                    $crystal->configureEncounterState(true, true);
                    continue 2;
                }
            }
            $outcome = ($this->spawn)(new EntitySpawnRequest(
                VanillaEntityType::ENDER_CRYSTAL,
                SpawnCause::CHUNK_LOAD,
                $this->worldName,
                $position,
            ));
            if ($outcome->entity instanceof EndCrystalEntity) {
                $outcome->entity->configureEncounterState(true, true);
                $existing[] = $outcome->entity;
            }
        }
    }

    private function nearestCrystal(Position $position): ?EndCrystalEntity
    {
        $nearest = null;
        $nearestSquared = 64.0 * 64.0;
        foreach ($this->entities->nearby($this->worldName, $position, 64.0, 32) as $candidate) {
            if (!$candidate instanceof EndCrystalEntity || !$candidate->isAlive()) {
                continue;
            }
            $candidatePosition = $candidate->internalPosition();
            $distance = ($candidatePosition->x - $position->x) ** 2
                + ($candidatePosition->y - $position->y) ** 2
                + ($candidatePosition->z - $position->z) ** 2;
            if ($distance < $nearestSquared) {
                $nearest = $candidate;
                $nearestSquared = $distance;
            }
        }

        return $nearest;
    }

    /** @return list<EnderDragonAttack> */
    private function advanceDragonFlight(EnderDragonEntity $dragon, int $tick): array
    {
        $phaseTicks = $tick % 600;
        $phase = $this->phaseLockTicks > 0
            ? $this->state->phase
            : match (true) {
                $phaseTicks < 360 => EnderDragonPhase::CIRCLING,
                $phaseTicks < 440 => EnderDragonPhase::STRAFING,
                $phaseTicks < 500 => EnderDragonPhase::LANDING,
                $phaseTicks < 560 => EnderDragonPhase::PERCHED,
                default => EnderDragonPhase::TAKEOFF,
            };
        $this->phaseLockTicks = max(0, $this->phaseLockTicks - 1);
        if ($phase !== $this->state->phase) {
            $phase = $this->phaseChange === null
                ? $phase
                : (($this->phaseChange)($this->state->phase, $phase) ?? $this->state->phase);
            if ($phase !== $this->state->phase) {
                $this->replace($this->state->withPhase($phase));
            }
        }
        $angle = deg2rad(fmod(($tick + $dragon->getRuntimeId()) * 1.8, 360.0));
        $targetRadius = $phase === EnderDragonPhase::PERCHED ? 6.0 : 52.0;
        $targetY = match ($phase) {
            EnderDragonPhase::LANDING, EnderDragonPhase::PERCHED => 72.0,
            EnderDragonPhase::TAKEOFF => 88.0,
            default => 90.0 + sin($angle * 2.0) * 8.0,
        };
        $current = $dragon->internalPosition();
        $targetX = cos($angle) * $targetRadius;
        $targetZ = sin($angle) * $targetRadius;
        $speed = $phase === EnderDragonPhase::PERCHED ? 0.0 : 0.55;
        $dx = $targetX - $current->x;
        $dy = $targetY - $current->y;
        $dz = $targetZ - $current->z;
        $distance = max(0.001, hypot(hypot($dx, $dz), $dy));
        $dragon->setMotion(new EntityMotion($dx / $distance * $speed, $dy / $distance * $speed, $dz / $distance * $speed));

        $target = $this->nearestTarget($current, 128.0);
        if ($target === null) {
            return [];
        }
        $targetDistance = $current->distanceTo($target->position);
        if ($phase === EnderDragonPhase::STRAFING && $tick % 40 === 0) {
            return [new EnderDragonAttack(
                EnderDragonAttackType::FIREBALL,
                new Position($current->x, $current->y + 2.0, $current->z),
                $target->position,
                $target->uuid,
                6.0,
                4.0,
                0.0,
            )];
        }
        if ($phase === EnderDragonPhase::PERCHED && $tick % 40 === 0 && $targetDistance <= 32.0) {
            return [new EnderDragonAttack(
                EnderDragonAttackType::BREATH,
                $current,
                $target->position,
                $target->uuid,
                3.0,
                3.0,
                0.0,
            )];
        }
        if ($tick % 10 === 0 && $targetDistance <= 8.0
            && in_array($phase, [EnderDragonPhase::LANDING, EnderDragonPhase::PERCHED, EnderDragonPhase::TAKEOFF], true)) {
            return [new EnderDragonAttack(
                EnderDragonAttackType::CONTACT,
                $current,
                $target->position,
                $target->uuid,
                10.0,
                6.0,
                1.2,
            )];
        }

        return [];
    }

    private function nearestTarget(Position $origin, float $maximumDistance): ?EndEncounterTarget
    {
        if ($this->targets === null) {
            return null;
        }
        $nearest = null;
        $nearestDistance = $maximumDistance;
        foreach (($this->targets)() as $target) {
            $distance = $origin->distanceTo($target->position);
            if ($distance < $nearestDistance) {
                $nearest = $target;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    private function advanceRespawn(int $tick): EndEncounterTickResult
    {
        $ritualValid = $this->ritualCrystalsRemainValid();
        if ($ritualValid === null) {
            return new EndEncounterTickResult();
        }
        if (!$ritualValid) {
            $this->clearRitualCrystalBeams();
            $this->replace($this->state->abortRespawn());
            $this->save();

            return new EndEncounterTickResult(true);
        }
        $ticks = $this->state->respawnTicks + 1;
        $this->synchronizeRitualCrystalBeams(new Position(0.0, 128.0, 0.0));
        $stage = match (true) {
            $ticks < 100 => EnderDragonRespawnStage::STARTING,
            $ticks < 200 => EnderDragonRespawnStage::PREPARING_PILLARS,
            $ticks < 600 => EnderDragonRespawnStage::SUMMONING_PILLARS,
            default => EnderDragonRespawnStage::SUMMONING_DRAGON,
        };
        if ($ticks < 700) {
            $workIndex = $this->state->respawnWorkIndex;
            if ($stage === EnderDragonRespawnStage::SUMMONING_PILLARS) {
                $workIndex = $this->regeneratePillarWork($workIndex, 2);
            }
            $this->replace($this->state->advanceRespawn($stage, $ticks, $workIndex));
            if ($ticks % 20 === 0) {
                $this->save();
            }
            return new EndEncounterTickResult(true);
        }
        foreach ($this->state->ritualCrystalUuids as $uuid) {
            $candidate = $this->entities->getByUniqueId($uuid);
            if ($candidate instanceof EndCrystalEntity) {
                $this->entities->remove($candidate->getRuntimeId());
            }
        }
        $this->ensureInitialCrystals();
        $dragon = $this->spawnDragon();
        if ($dragon === null) {
            return new EndEncounterTickResult();
        }
        $this->replace($this->state->withDragon($dragon->getUniqueId()));
        $this->save();

        return new EndEncounterTickResult(true, true);
    }

    private function activateExitFountain(): bool
    {
        if ($this->world === null || $this->blocks === null) {
            return false;
        }
        $air = $this->blockState('minecraft:air');
        $bedrock = $this->blockState('minecraft:bedrock');
        $endStone = $this->blockState('minecraft:end_stone');
        $portal = $this->blockState('minecraft:end_portal');
        $egg = $this->blockState('minecraft:dragon_egg');
        $preserveEgg = $this->state->dragonEggPlaced && (
            $this->world->blockStateAt(0, 73, 0)->value === $egg->value
            || $this->world->blockStateAt(0, 74, 0)->value === $egg->value
        );
        for ($x = -4; $x <= 4; ++$x) {
            for ($z = -4; $z <= 4; ++$z) {
                $distance = hypot((float) $x, (float) $z);
                if ($distance >= 3.5) {
                    continue;
                }
                $insideRim = $distance < 2.5;
                $this->setEncounterBlock(new BlockPosition($x, 68, $z), $insideRim ? $bedrock : $endStone);
                $this->setEncounterBlock(new BlockPosition($x, 69, $z), $insideRim ? $portal : $bedrock);
                for ($y = 70; $y <= 101; ++$y) {
                    $this->setEncounterBlock(new BlockPosition($x, $y, $z), $air);
                }
            }
        }
        for ($y = 69; $y <= 72; ++$y) {
            $this->setEncounterBlock(new BlockPosition(0, $y, 0), $bedrock);
        }
        if (!$this->state->dragonEggPlaced || $preserveEgg) {
            $this->setEncounterBlock(new BlockPosition(0, 73, 0), $egg);
        }
        $this->exitFountainReconciled = true;

        return true;
    }

    /** @return null|list<EndCrystalEntity> */
    private function respawnCrystals(): ?array
    {
        $expected = [
            new Position(3.5, 70.0, 0.5), new Position(-2.5, 70.0, 0.5),
            new Position(0.5, 70.0, 3.5), new Position(0.5, 70.0, -2.5),
        ];
        $crystals = array_values(array_filter(
            $this->entities->nearby($this->worldName, new Position(0.5, 70.0, 0.5), 8.0, 16),
            static fn($entity): bool => $entity instanceof EndCrystalEntity && $entity->isAlive(),
        ));
        $owned = [];
        foreach ($expected as $position) {
            $matched = null;
            foreach ($crystals as $crystal) {
                if (!in_array($crystal, $owned, true) && $crystal->internalPosition()->distanceTo($position) <= 0.8) {
                    $matched = $crystal;
                    break;
                }
            }
            if (!$matched instanceof EndCrystalEntity) {
                return null;
            }
            $owned[] = $matched;
        }

        return $owned;
    }

    /** null means persisted ritual actors are still within the bounded restart recovery window. */
    private function ritualCrystalsRemainValid(): ?bool
    {
        if (count($this->state->ritualCrystalUuids) !== 4) {
            return false;
        }
        $expected = [
            new Position(3.5, 70.0, 0.5), new Position(-2.5, 70.0, 0.5),
            new Position(0.5, 70.0, 3.5), new Position(0.5, 70.0, -2.5),
        ];
        foreach ($this->state->ritualCrystalUuids as $index => $uuid) {
            $crystal = $this->entities->getByUniqueId($uuid);
            if ($crystal === null && $this->ritualRecoveryGraceTicks > 0) {
                --$this->ritualRecoveryGraceTicks;
                return null;
            }
            if (!$crystal instanceof EndCrystalEntity || !$crystal->isAlive()
                || $crystal->getWorldName() !== $this->worldName
                || $crystal->internalPosition()->distanceTo($expected[$index]) > 0.8) {
                return false;
            }
        }

        return true;
    }

    private function synchronizeHealingCrystalBeam(
        EnderDragonEntity $dragon,
        ?EndCrystalEntity $healingCrystal,
    ): void {
        foreach ($this->entities->all() as $candidate) {
            if (!$candidate instanceof EndCrystalEntity || !$candidate->isAlive()
                || $candidate->getWorldName() !== $this->worldName || !$candidate->isEncounterOwned()) {
                continue;
            }
            $candidate->configureEncounterState(
                true,
                $candidate->showsBase(),
                $candidate === $healingCrystal ? $dragon->internalPosition() : null,
                $candidate->isInvulnerable(),
            );
        }
    }

    private function synchronizeRitualCrystalBeams(Position $target): void
    {
        foreach ($this->state->ritualCrystalUuids as $uuid) {
            $crystal = $this->entities->getByUniqueId($uuid);
            if ($crystal instanceof EndCrystalEntity && $crystal->isAlive()) {
                $crystal->configureEncounterState(true, $crystal->showsBase(), $target, true);
            }
        }
    }

    private function clearRitualCrystalBeams(): void
    {
        foreach ($this->state->ritualCrystalUuids as $uuid) {
            $crystal = $this->entities->getByUniqueId($uuid);
            if ($crystal instanceof EndCrystalEntity && $crystal->isAlive()) {
                $crystal->configureEncounterState(false, $crystal->showsBase());
            }
        }
    }

    /** Regenerates at most $budget bounded pillar layers and returns the durable cursor. */
    private function regeneratePillarWork(int $cursor, int $budget): int
    {
        if ($this->world === null || $this->blocks === null) {
            return $cursor;
        }
        $positions = EndArenaLayout::crystalPositions();
        $obsidian = $this->blockState('minecraft:obsidian');
        $bars = $this->blockState('minecraft:iron_bars');
        $stepsPerPillar = 62;
        $maximum = count($positions) * $stepsPerPillar;
        for ($work = 0; $work < $budget && $cursor < $maximum; ++$work, ++$cursor) {
            $pillarIndex = intdiv($cursor, $stepsPerPillar);
            $layer = $cursor % $stepsPerPillar;
            $top = (int) floor($positions[$pillarIndex]->y) - 1;
            $centerX = (int) floor($positions[$pillarIndex]->x);
            $centerZ = (int) floor($positions[$pillarIndex]->z);
            if ($layer < 60) {
                $y = $top - 59 + $layer;
                for ($dx = -2; $dx <= 2; ++$dx) {
                    for ($dz = -2; $dz <= 2; ++$dz) {
                        if (($dx * $dx) + ($dz * $dz) <= 6) {
                            $this->setEncounterBlock(new BlockPosition($centerX + $dx, $y, $centerZ + $dz), $obsidian);
                        }
                    }
                }
                continue;
            }
            if ($pillarIndex % 3 !== 1) {
                continue;
            }
            $cageY = $top + ($layer - 59);
            for ($offset = -2; $offset <= 2; ++$offset) {
                $this->setEncounterBlock(new BlockPosition($centerX - 2, $cageY, $centerZ + $offset), $bars);
                $this->setEncounterBlock(new BlockPosition($centerX + 2, $cageY, $centerZ + $offset), $bars);
                $this->setEncounterBlock(new BlockPosition($centerX + $offset, $cageY, $centerZ - 2), $bars);
                $this->setEncounterBlock(new BlockPosition($centerX + $offset, $cageY, $centerZ + 2), $bars);
            }
        }

        return $cursor;
    }

    private function activateNextGateway(): ?BlockPosition
    {
        if ($this->world === null || $this->blocks === null
            || $this->state->gatewayCount >= EndGatewayPlanner::GATEWAY_COUNT) {
            return null;
        }
        $order = EndGatewayPlanner::order($this->seed);
        $slot = $order[$this->state->gatewayCount];
        $position = EndGatewayPlanner::inner($slot);
        if ($this->gatewayPosition !== null) {
            $position = ($this->gatewayPosition)($slot, $position);
            if (!$position instanceof BlockPosition) {
                return null;
            }
        }
        $bedrock = $this->blockState('minecraft:bedrock');
        $gateway = $this->blockState('minecraft:end_gateway');
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dy = -2; $dy <= 2; ++$dy) {
                if (abs($dx) === 1 || abs($dy) === 2) {
                    $this->setEncounterBlock(new BlockPosition($position->x + $dx, $position->y + $dy, $position->z), $bedrock);
                    $this->setEncounterBlock(new BlockPosition($position->x, $position->y + $dy, $position->z + $dx), $bedrock);
                }
            }
        }
        $this->setEncounterBlock($position, $gateway);

        return $position;
    }

    private function replace(EndEncounterState $state): void
    {
        $this->state = $state;
        if ($state->revision % 20 === 0) {
            $this->save();
        }
    }

    private function blockState(string $identifier): InternalBlockStateId
    {
        $blocks = $this->blocks ?? throw new \LogicException('End encounter block registry is unavailable.');
        foreach ($blocks->states() as $state) {
            if ($state->identifier() === $identifier && $state->properties() === []) {
                return $blocks->internalId($state);
            }
        }
        foreach ($blocks->states() as $state) {
            if ($state->identifier() === $identifier) {
                return $blocks->internalId($state);
            }
        }

        throw new \LogicException("Required End encounter block '$identifier' is not registered.");
    }

    private function setEncounterBlock(BlockPosition $position, InternalBlockStateId $state): void
    {
        if ($this->setBlock !== null) {
            ($this->setBlock)($position, $state);
            return;
        }
        $world = $this->world ?? throw new \LogicException('End encounter world is unavailable.');
        $world->setBlockState($position->x, $position->y, $position->z, $state);
    }

    private function save(): void
    {
        if ($this->repository === null || $this->lastSavedRevision === $this->state->revision) {
            return;
        }
        $this->repository->save($this->state);
        $this->lastSavedRevision = $this->state->revision;
    }
}
