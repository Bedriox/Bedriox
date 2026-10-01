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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\World;
use Closure;
use InvalidArgumentException;
use SplQueue;

/** Bounded production coordinator for deterministic natural spawning and hard-distance despawning. */
final class WorldNaturalSpawnRuntime
{
    public const int DEFAULT_CADENCE_TICKS = 20;
    public const int DEFAULT_MAXIMUM_DESPAWN_CHECKS = 32;
    public const int DEFAULT_MONSTER_WORLD_CAP = 24;
    public const int DEFAULT_MONSTER_LOCAL_DENSITY_CAP = 2;
    public const int DEFAULT_MAXIMUM_SPAWNS_PER_CYCLE = 2;
    public const int DEFAULT_MAXIMUM_EXCESS_DESPAWNS_PER_CYCLE = 2;

    /** @var array<int, true> Runtime IDs created specifically by this natural-spawn owner. */
    private array $naturalRuntimeIds = [];

    /** @var SplQueue<int> */
    private SplQueue $despawnQueue;

    /** @var null|Closure(AbstractLivingEntity): bool */
    private readonly ?Closure $beforeDespawn;

    public function __construct(
        private readonly EntityWorldRuntime $entities,
        private readonly WorldNaturalSpawnEnvironment $environment,
        private readonly NaturalSpawner $spawner,
        private readonly NaturalDespawnPolicy $despawnPolicy,
        private readonly string $worldName,
        private readonly int $worldSeed,
        private readonly int $cadenceTicks = self::DEFAULT_CADENCE_TICKS,
        private readonly int $maximumDespawnChecks = self::DEFAULT_MAXIMUM_DESPAWN_CHECKS,
        ?Closure $beforeDespawn = null,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1
            || $cadenceTicks < 1 || $cadenceTicks > 1_200
            || $maximumDespawnChecks < 1 || $maximumDespawnChecks > 4_096) {
            throw new InvalidArgumentException('Natural-spawn runtime budgets are invalid.');
        }
        $this->beforeDespawn = $beforeDespawn;
        $this->despawnQueue = new SplQueue();
    }

    public static function baseline(
        World $world,
        EntityWorldRuntime $entities,
        EntityDefinitionRegistry $definitions,
        BlockStateRegistry $states,
        BlockCollisionRegistry $shapes,
        BlockCollisionQuery $collisions,
        InternalBlockStateId $air,
        ?InternalBlockStateId $water = null,
        ?InternalBlockStateId $lava = null,
        ?NaturalSpawnClock $clock = null,
        bool $spawnAnimals = true,
        bool $spawnMonsters = true,
        ?Closure $beforeDespawn = null,
        ?string $worldName = null,
    ): self {
        $environment = new WorldNaturalSpawnEnvironment(
            $world,
            $entities->registry(),
            $definitions,
            $states,
            $shapes,
            $collisions,
            $air,
            $water,
            $lava,
        );
        $limits = new NaturalSpawnLimits(
            candidateRadius: 7,
            maximumAttempts: 24,
            maximumSpawns: self::DEFAULT_MAXIMUM_SPAWNS_PER_CYCLE,
            maximumElapsedNanoseconds: 750_000,
            minimumPlayerDistance: 24.0,
            minimumWorldSpawnDistance: 24.0,
            categories: [
                new NaturalSpawnCategoryLimit(EntityCategory::ANIMAL, 10, 4),
                new NaturalSpawnCategoryLimit(
                    EntityCategory::MONSTER,
                    self::DEFAULT_MONSTER_WORLD_CAP,
                    self::DEFAULT_MONSTER_LOCAL_DENSITY_CAP,
                ),
            ],
        );
        $entries = [];
        if ($spawnAnimals) {
            $entries[] = new NaturalSpawnEntry(
                VanillaEntityType::COW,
                EntityCategory::ANIMAL,
                new CowNaturalSpawnRule(),
                8,
            );
            $entries[] = new NaturalSpawnEntry(
                VanillaEntityType::SHEEP,
                EntityCategory::ANIMAL,
                new SheepNaturalSpawnRule(),
                12,
            );
            if ($definitions->get(VanillaEntityType::PIG) !== null) {
                $entries[] = new NaturalSpawnEntry(VanillaEntityType::PIG, EntityCategory::ANIMAL, new PigNaturalSpawnRule(), 10);
            }
            if ($definitions->get(VanillaEntityType::CHICKEN) !== null) {
                $entries[] = new NaturalSpawnEntry(VanillaEntityType::CHICKEN, EntityCategory::ANIMAL, new ChickenNaturalSpawnRule(), 10);
            }
            if ($definitions->get(VanillaEntityType::RABBIT) !== null) {
                $entries[] = new NaturalSpawnEntry(VanillaEntityType::RABBIT, EntityCategory::ANIMAL, new RabbitNaturalSpawnRule(), 6);
            }
        }
        if ($spawnMonsters && $world->difficulty() > 0) {
            $entries[] = new NaturalSpawnEntry(
                VanillaEntityType::ZOMBIE,
                EntityCategory::MONSTER,
                new ZombieNaturalSpawnRule(),
                12,
            );
            $entries[] = new NaturalSpawnEntry(
                VanillaEntityType::SKELETON,
                EntityCategory::MONSTER,
                new SkeletonNaturalSpawnRule(),
                10,
            );
            foreach ([
                [VanillaEntityType::HUSK, new HuskNaturalSpawnRule(), 5],
                [VanillaEntityType::STRAY, new StrayNaturalSpawnRule(), 4],
                [VanillaEntityType::BOGGED, new BoggedNaturalSpawnRule(), 3],
                [VanillaEntityType::PARCHED, new ParchedNaturalSpawnRule(), 3],
                [VanillaEntityType::SPIDER, new SpiderNaturalSpawnRule(), 10],
                [VanillaEntityType::CREEPER, new CreeperNaturalSpawnRule(), 10],
                [VanillaEntityType::SLIME, new SlimeNaturalSpawnRule(), 3],
                [VanillaEntityType::MAGMA_CUBE, new MagmaCubeNaturalSpawnRule(), 4],
                [VanillaEntityType::ENDERMAN, new EndermanNaturalSpawnRule(), 1],
                [VanillaEntityType::WITCH, new WitchNaturalSpawnRule(), 1],
            ] as [$type, $rule, $weight]) {
                if ($definitions->get($type) !== null) {
                    $entries[] = new NaturalSpawnEntry($type, EntityCategory::MONSTER, $rule, $weight);
                }
            }
        }
        $spawner = new NaturalSpawner(
            new NaturalSpawnCandidatePlanner(),
            $environment,
            $clock ?? new SystemNaturalSpawnClock(),
            $limits,
            $entries,
        );

        return new self(
            $entities,
            $environment,
            $spawner,
            new NaturalDespawnPolicy(600, [
                EntityCategory::ANIMAL->value => 128.0,
                EntityCategory::MONSTER->value => 128.0,
            ], [
                EntityCategory::MONSTER->value => 32.0,
            ]),
            $worldName ?? $world->metadata->name,
            $world->metadata->seed,
            beforeDespawn: $beforeDespawn,
        );
    }

    /** @param array<int, NaturalSpawnPlayer> $players */
    public function tick(int $tick, array $players): WorldNaturalEntityTick
    {
        if ($tick < 0) {
            throw new InvalidArgumentException('Natural-spawn runtime tick cannot be negative.');
        }
        $this->environment->updateContext($players);
        if ($tick % $this->cadenceTicks !== 0) {
            return new WorldNaturalEntityTick();
        }

        $spawned = [];
        $removed = [];
        if ($players !== []) {
            $batch = $this->spawner->plan(
                $this->worldName,
                $players,
                $this->worldSeed,
                $tick,
            );
            foreach ($batch->requests() as $request) {
                $outcome = $this->entities->spawn($request);
                if ($outcome->entity instanceof AbstractLivingEntity) {
                    $this->restoreDespawnOwnership($outcome->entity);
                    $spawned[] = $outcome->entity;
                }
            }
            $monsterCap = $this->spawner->effectiveCategoryCap(EntityCategory::MONSTER, $batch->candidateCount);
            if ($monsterCap !== null) {
                $removed = $this->trimExcessNaturalCategory(EntityCategory::MONSTER, $monsterCap, $players);
            }
        }

        array_push($removed, ...$this->despawn($players));

        return new WorldNaturalEntityTick($spawned, $removed);
    }

    public function trackedCount(): int
    {
        return count($this->naturalRuntimeIds);
    }

    /** Restores durable natural-distance ownership after spawn or chunk activation. */
    public function restoreDespawnOwnership(AbstractLivingEntity $entity): void
    {
        if ($entity->despawnPolicy() !== EntityDespawnPolicy::NATURAL_DISTANCE) {
            return;
        }
        $runtimeId = $entity->getRuntimeId();
        if ($entity->getWorldName() !== $this->worldName
            || $this->entities->registry()->getByRuntimeId($runtimeId) !== $entity) {
            throw new InvalidArgumentException('Natural-despawn ownership requires a registered entity in this world.');
        }
        if (isset($this->naturalRuntimeIds[$runtimeId])) {
            return;
        }
        $this->naturalRuntimeIds[$runtimeId] = true;
        $this->despawnQueue->enqueue($runtimeId);
    }

    /**
     * @param array<int, NaturalSpawnPlayer> $players
     *
     * @return list<AbstractLivingEntity>
     */
    private function despawn(array $players): array
    {
        $count = count($this->naturalRuntimeIds);
        if ($count === 0) {
            return [];
        }
        $checks = min($count, $this->maximumDespawnChecks);
        $removed = [];
        for ($checked = 0; $checked < $checks && !$this->despawnQueue->isEmpty(); ++$checked) {
            $runtimeId = $this->despawnQueue->dequeue();
            if (!isset($this->naturalRuntimeIds[$runtimeId])) {
                continue;
            }
            $entity = $this->entities->registry()->getByRuntimeId($runtimeId);
            if (!$entity instanceof AbstractLivingEntity) {
                unset($this->naturalRuntimeIds[$runtimeId]);
                continue;
            }
            if ($entity->despawnPolicy() !== EntityDespawnPolicy::NATURAL_DISTANCE) {
                unset($this->naturalRuntimeIds[$runtimeId]);
                continue;
            }
            $decision = $this->despawnPolicy->decide(new NaturalDespawnState(
                $entity->getWorldName(),
                $entity->getCategory(),
                $entity->internalPosition(),
                min($entity->ageTicks(), 0x7fffffff),
            ), $players);
            if (!in_array($decision, [
                NaturalDespawnDecision::DESPAWN_DISTANCE,
                NaturalDespawnDecision::DESPAWN_SOFT_DISTANCE,
            ], true)) {
                $this->despawnQueue->enqueue($runtimeId);
                continue;
            }
            if ($this->beforeDespawn !== null && !($this->beforeDespawn)($entity)) {
                $this->despawnQueue->enqueue($runtimeId);
                continue;
            }
            $despawned = $this->entities->remove($runtimeId);
            unset($this->naturalRuntimeIds[$runtimeId]);
            if ($despawned instanceof AbstractLivingEntity) {
                $removed[] = $despawned;
            }
        }

        return $removed;
    }

    /**
     * Retires a bounded number of far-away natural entities when a lower cap is deployed.
     * Command, spawn-egg, and plugin-owned entities are never selected.
     *
     * @param array<int, NaturalSpawnPlayer> $players
     * @return list<AbstractLivingEntity>
     */
    private function trimExcessNaturalCategory(EntityCategory $category, int $cap, array $players): array
    {
        $excess = $this->environment->categoryCount($this->worldName, $category) - $cap;
        if ($excess <= 0) {
            return [];
        }
        /** @var list<array{float, int, AbstractLivingEntity}> $candidates */
        $candidates = [];
        foreach (array_keys($this->naturalRuntimeIds) as $runtimeId) {
            $entity = $this->entities->registry()->getByRuntimeId($runtimeId);
            if (!$entity instanceof AbstractLivingEntity || $entity->getCategory() !== $category) {
                continue;
            }
            $nearest = PHP_FLOAT_MAX;
            foreach ($players as $player) {
                if ($player->worldName !== $entity->getWorldName()) {
                    continue;
                }
                $position = $entity->internalPosition();
                $dx = $position->x - $player->position->x;
                $dy = $position->y - $player->position->y;
                $dz = $position->z - $player->position->z;
                $nearest = min($nearest, ($dx * $dx) + ($dy * $dy) + ($dz * $dz));
            }
            $candidates[] = [$nearest, $runtimeId, $entity];
        }
        usort(
            $candidates,
            static function (array $left, array $right): int {
                $distance = $right[0] <=> $left[0];

                return $distance !== 0 ? $distance : $right[1] <=> $left[1];
            },
        );

        $removed = [];
        $limit = min($excess, self::DEFAULT_MAXIMUM_EXCESS_DESPAWNS_PER_CYCLE);
        foreach ($candidates as [, $runtimeId, $entity]) {
            if (count($removed) >= $limit) {
                break;
            }
            if ($this->beforeDespawn !== null && !($this->beforeDespawn)($entity)) {
                continue;
            }
            $despawned = $this->entities->remove($runtimeId);
            unset($this->naturalRuntimeIds[$runtimeId]);
            if ($despawned instanceof AbstractLivingEntity) {
                $removed[] = $despawned;
            }
        }

        return $removed;
    }
}
