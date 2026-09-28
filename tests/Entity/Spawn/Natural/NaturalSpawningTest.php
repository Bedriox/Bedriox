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

namespace Bedriox\Server\Tests\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\Spawn\Natural\NaturalDespawnDecision;
use Bedriox\Server\Entity\Spawn\Natural\NaturalDespawnPolicy;
use Bedriox\Server\Entity\Spawn\Natural\NaturalDespawnRandom;
use Bedriox\Server\Entity\Spawn\Natural\NaturalDespawnState;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnCandidatePlanner;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnCategoryLimit;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnClock;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnContext;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnEntry;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnEnvironment;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawner;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnLimits;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnPlayer;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnRule;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class NaturalSpawningTest extends TestCase
{
    public function testCandidateChunksDeduplicateOverlappingPlayerRegionsDeterministically(): void
    {
        $planner = new NaturalSpawnCandidatePlanner();
        $players = [
            new NaturalSpawnPlayer('world', new Position(1.0, 64.0, 1.0)),
            new NaturalSpawnPlayer('world', new Position(17.0, 64.0, 1.0)),
            new NaturalSpawnPlayer('other', new Position(1_000.0, 64.0, 1_000.0)),
        ];

        $first = $planner->plan('world', $players, 1, 12345, 700);
        $second = $planner->plan('world', $players, 1, 12345, 700);

        self::assertEquals($first, $second);
        self::assertCount(12, $first);
        self::assertCount(12, array_unique(array_map(static fn(ChunkPosition $chunk): string => $chunk->key(), $first)));
        self::assertNotEquals($first, $planner->plan('world', $players, 1, 12345, 701));
    }

    public function testCandidatePlanningFairlySamplesTheSupportedMaximumPlayerCount(): void
    {
        $planner = new NaturalSpawnCandidatePlanner();
        $players = [];
        for ($index = 0; $index < NaturalSpawnCandidatePlanner::MAXIMUM_PLAYERS; ++$index) {
            $players[] = new NaturalSpawnPlayer('world', new Position($index * 512.0, 64.0, 0.0));
        }

        $first = $planner->plan('world', $players, 7, 12345, 700);
        $second = $planner->plan('world', $players, 7, 12345, 700);
        $firstKeys = array_fill_keys(array_map(static fn(ChunkPosition $chunk): string => $chunk->key(), $first), true);
        $secondKeys = array_fill_keys(array_map(static fn(ChunkPosition $chunk): string => $chunk->key(), $second), true);

        self::assertLessThanOrEqual(NaturalSpawnCandidatePlanner::MAXIMUM_CANDIDATES, count($first));
        self::assertLessThanOrEqual(NaturalSpawnCandidatePlanner::MAXIMUM_CANDIDATES, count($second));
        self::assertTrue(self::containsChunkNearPlayer($firstKeys, 0));
        self::assertFalse(self::containsChunkNearPlayer($firstKeys, NaturalSpawnCandidatePlanner::MAXIMUM_CANDIDATES));
        self::assertTrue(self::containsChunkNearPlayer($secondKeys, NaturalSpawnCandidatePlanner::MAXIMUM_CANDIDATES));
    }

    /** @param array<string, true> $keys */
    private static function containsChunkNearPlayer(array $keys, int $playerIndex): bool
    {
        $centerX = $playerIndex * 32;
        for ($x = $centerX - 7; $x <= $centerX + 7; ++$x) {
            for ($z = -7; $z <= 7; ++$z) {
                if (isset($keys[(new ChunkPosition($x, $z))->key()])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testNaturalRequestsUseExistingTransactionShapeAndDeterministicTransforms(): void
    {
        $players = [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))];
        $first = self::spawner(new TestNaturalSpawnEnvironment(), new TestNaturalSpawnClock())->plan(
            'world',
            $players,
            99,
            1_000,
        );
        $second = self::spawner(new TestNaturalSpawnEnvironment(), new TestNaturalSpawnClock())->plan(
            'world',
            $players,
            99,
            1_000,
        );

        self::assertNotEmpty($first->requests());
        self::assertEquals($first->requests(), $second->requests());
        foreach ($first->requests() as $request) {
            self::assertSame(SpawnCause::NATURAL, $request->cause);
            self::assertSame('world', $request->worldName);
        }
    }

    public function testWorldAndLocalCapsIncludeRequestsAlreadyPlannedInTheBatch(): void
    {
        $players = [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))];
        $environment = new TestNaturalSpawnEnvironment();
        $limits = self::limits(worldCap: 1, localCap: 10, maximumAttempts: 9, maximumSpawns: 9);
        $batch = self::spawner($environment, new TestNaturalSpawnClock(), $limits, [
            new NaturalSpawnEntry(VanillaEntityType::COW, EntityCategory::ANIMAL, new AllowNaturalSpawnRule()),
        ])->plan('world', $players, 1, 20);
        self::assertCount(1, $batch->requests());

        $environment = new TestNaturalSpawnEnvironment();
        $environment->localCounts[EntityCategory::ANIMAL->value] = 1;
        $localBlocked = self::spawner(
            $environment,
            new TestNaturalSpawnClock(),
            self::limits(worldCap: 10, localCap: 1),
            [new NaturalSpawnEntry(VanillaEntityType::COW, EntityCategory::ANIMAL, new AllowNaturalSpawnRule())],
        )->plan('world', $players, 1, 20);
        self::assertSame([], $localBlocked->requests());
    }

    public function testWorldCapScalesWithDeduplicatedPlayerSpawnRegions(): void
    {
        $environment = new TestNaturalSpawnEnvironment();
        $limits = self::limits(worldCap: 1, localCap: 10, maximumAttempts: 18, maximumSpawns: 4);
        $entries = [
            new NaturalSpawnEntry(VanillaEntityType::COW, EntityCategory::ANIMAL, new AllowNaturalSpawnRule()),
        ];
        $single = self::spawner($environment, new TestNaturalSpawnClock(), $limits, $entries)->plan(
            'world',
            [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))],
            1,
            20,
        );
        self::assertCount(1, $single->requests());

        $separate = self::spawner(new TestNaturalSpawnEnvironment(), new TestNaturalSpawnClock(), $limits, $entries)->plan(
            'world',
            [
                new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0)),
                new NaturalSpawnPlayer('world', new Position(512.0, 64.0, 0.0)),
            ],
            1,
            20,
        );
        self::assertCount(2, $separate->requests());
    }

    public function testEffectiveWorldCapUsesTheCandidateRegionArea(): void
    {
        $limits = self::limits(worldCap: 24);

        self::assertSame(0, $limits->effectiveWorldCap(EntityCategory::MONSTER, 0));
        self::assertSame(24, $limits->effectiveWorldCap(EntityCategory::MONSTER, 9));
        self::assertSame(48, $limits->effectiveWorldCap(EntityCategory::MONSTER, 18));
    }

    public function testAttemptAndTimeBudgetsAdvanceFairEntryAndCandidateCursors(): void
    {
        $environment = new TestNaturalSpawnEnvironment();
        $spawner = self::spawner(
            $environment,
            new TestNaturalSpawnClock(),
            self::limits(maximumAttempts: 1, maximumSpawns: 1),
            [
                new NaturalSpawnEntry(VanillaEntityType::COW, EntityCategory::ANIMAL, new AllowNaturalSpawnRule()),
                new NaturalSpawnEntry(VanillaEntityType::ZOMBIE, EntityCategory::MONSTER, new AllowNaturalSpawnRule()),
            ],
        );
        $players = [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))];
        $first = $spawner->plan('world', $players, 7, 80);
        $second = $spawner->plan('world', $players, 7, 80);
        self::assertSame(VanillaEntityType::COW, $first->requests()[0]->type);
        self::assertSame(VanillaEntityType::ZOMBIE, $second->requests()[0]->type);
        self::assertNotEquals($first->requests()[0]->position, $second->requests()[0]->position);
        self::assertTrue($first->attemptBudgetExhausted);

        $timed = self::spawner(
            new TestNaturalSpawnEnvironment(),
            new TestNaturalSpawnClock([0, 0, 0, 10]),
            self::limits(maximumAttempts: 9, maximumSpawns: 9, elapsedNanoseconds: 5),
        )->plan('world', $players, 7, 80);
        self::assertSame(1, $timed->attempts);
        self::assertTrue($timed->timeBudgetExhausted);
    }

    public function testCandidatePreparationConsumesTheElapsedBudget(): void
    {
        $environment = new TestNaturalSpawnEnvironment();
        $batch = self::spawner(
            $environment,
            new TestNaturalSpawnClock([0, 10]),
            self::limits(maximumAttempts: 9, maximumSpawns: 9, elapsedNanoseconds: 5),
        )->plan(
            'world',
            [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))],
            7,
            80,
        );

        self::assertSame(0, $batch->attempts);
        self::assertTrue($batch->timeBudgetExhausted);
        self::assertSame(0, $environment->heightQueries);
    }

    public function testUnloadedOrUnstableChunksRejectBeforeEnvironmentWork(): void
    {
        $environment = new TestNaturalSpawnEnvironment();
        $environment->loaded = false;
        $batch = self::spawner($environment, new TestNaturalSpawnClock())->plan(
            'world',
            [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))],
            1,
            1,
        );

        self::assertSame([], $batch->requests());
        self::assertSame(0, $environment->heightQueries);

        $environment = new TestNaturalSpawnEnvironment();
        $environment->stable = false;
        $batch = self::spawner($environment, new TestNaturalSpawnClock())->plan(
            'world',
            [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))],
            1,
            1,
        );
        self::assertSame([], $batch->requests());
        self::assertSame(0, $environment->heightQueries);
    }

    public function testTypedRuleReceivesEnvironmentAndCanRejectCandidate(): void
    {
        $environment = new TestNaturalSpawnEnvironment();
        $environment->medium = NaturalSpawnMedium::WATER;
        $rule = new GroundNaturalSpawnRule();
        $batch = self::spawner(
            $environment,
            new TestNaturalSpawnClock(),
            entries: [new NaturalSpawnEntry(VanillaEntityType::COW, EntityCategory::ANIMAL, $rule)],
        )->plan(
            'world',
            [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))],
            2,
            2,
        );

        self::assertSame([], $batch->requests());
        self::assertGreaterThan(0, $rule->evaluations);
        self::assertNotNull($rule->lastContext);
        self::assertSame('minecraft:overworld', $rule->lastContext->dimension);
        self::assertSame('minecraft:plains', $rule->lastContext->biome);
    }

    public function testDespawnPolicyHonorsEveryPersistenceExemptionAndDistance(): void
    {
        $policy = new NaturalDespawnPolicy(200, [
            EntityCategory::ANIMAL->value => 64.0,
        ]);
        $players = [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))];
        $base = [
            'world',
            EntityCategory::ANIMAL,
            new Position(100.0, 64.0, 0.0),
            500,
        ];
        $exemptions = [
            new NaturalDespawnState(...[...$base, true, false, false, false, false, false]),
            new NaturalDespawnState(...[...$base, false, true, false, false, false, false]),
            new NaturalDespawnState(...[...$base, false, false, true, false, false, false]),
            new NaturalDespawnState(...[...$base, false, false, false, true, false, false]),
            new NaturalDespawnState(...[...$base, false, false, false, false, true, false]),
            new NaturalDespawnState(...[...$base, false, false, false, false, false, true]),
        ];
        foreach ($exemptions as $entity) {
            self::assertSame(NaturalDespawnDecision::KEEP_EXEMPT, $policy->decide($entity, $players));
        }

        self::assertSame(
            NaturalDespawnDecision::DESPAWN_DISTANCE,
            $policy->decide(new NaturalDespawnState(...$base), $players),
        );
        self::assertSame(
            NaturalDespawnDecision::KEEP_NEAR_PLAYER,
            $policy->decide(
                new NaturalDespawnState('world', EntityCategory::ANIMAL, new Position(10.0, 64.0, 0.0), 500),
                $players,
            ),
        );
        self::assertSame(
            NaturalDespawnDecision::KEEP_YOUNG,
            $policy->decide(
                new NaturalDespawnState('world', EntityCategory::ANIMAL, new Position(100.0, 64.0, 0.0), 199),
                $players,
            ),
        );
        self::assertSame(
            NaturalDespawnDecision::KEEP_NO_PLAYERS,
            $policy->decide(new NaturalDespawnState(...$base), []),
        );
    }

    public function testMonsterSoftDespawnIsRandomizedBeyondTheActivationDistance(): void
    {
        $policy = new NaturalDespawnPolicy(
            200,
            [EntityCategory::MONSTER->value => 128.0],
            [EntityCategory::MONSTER->value => 32.0],
            40,
            new AlwaysNaturalDespawnRandom(),
        );
        $players = [new NaturalSpawnPlayer('world', new Position(0.0, 64.0, 0.0))];

        self::assertSame(
            NaturalDespawnDecision::DESPAWN_SOFT_DISTANCE,
            $policy->decide(new NaturalDespawnState(
                'world',
                EntityCategory::MONSTER,
                new Position(40.0, 64.0, 0.0),
                500,
            ), $players),
        );
        self::assertSame(
            NaturalDespawnDecision::KEEP_NEAR_PLAYER,
            $policy->decide(new NaturalDespawnState(
                'world',
                EntityCategory::MONSTER,
                new Position(31.0, 64.0, 0.0),
                500,
            ), $players),
        );
        self::assertSame(
            NaturalDespawnDecision::KEEP_NO_PLAYERS,
            $policy->decide(new NaturalDespawnState(
                'world',
                EntityCategory::MONSTER,
                new Position(200.0, 64.0, 0.0),
                500,
            ), []),
        );
    }

    /** @param array<int, NaturalSpawnEntry>|null $entries */
    private static function spawner(
        TestNaturalSpawnEnvironment $environment,
        NaturalSpawnClock $clock,
        ?NaturalSpawnLimits $limits = null,
        ?array $entries = null,
    ): NaturalSpawner {
        return new NaturalSpawner(
            new NaturalSpawnCandidatePlanner(),
            $environment,
            $clock,
            $limits ?? self::limits(),
            $entries ?? [
                new NaturalSpawnEntry(VanillaEntityType::COW, EntityCategory::ANIMAL, new AllowNaturalSpawnRule()),
            ],
        );
    }

    private static function limits(
        int $worldCap = 20,
        int $localCap = 8,
        int $maximumAttempts = 9,
        int $maximumSpawns = 4,
        int $elapsedNanoseconds = 1_000,
    ): NaturalSpawnLimits {
        return new NaturalSpawnLimits(
            1,
            $maximumAttempts,
            $maximumSpawns,
            $elapsedNanoseconds,
            0.0,
            0.0,
            [
                new NaturalSpawnCategoryLimit(EntityCategory::ANIMAL, $worldCap, $localCap),
                new NaturalSpawnCategoryLimit(EntityCategory::MONSTER, $worldCap, $localCap),
            ],
        );
    }
}

final class AllowNaturalSpawnRule implements NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool
    {
        return true;
    }
}

final class GroundNaturalSpawnRule implements NaturalSpawnRule
{
    public int $evaluations = 0;
    public ?NaturalSpawnContext $lastContext = null;

    public function allows(NaturalSpawnContext $context): bool
    {
        ++$this->evaluations;
        $this->lastContext = $context;

        return $context->medium === NaturalSpawnMedium::GROUND;
    }
}

final class TestNaturalSpawnClock implements NaturalSpawnClock
{
    private int $index = 0;

    /** @param list<int> $values */
    public function __construct(private readonly array $values = [0]) {}

    public function nowNanoseconds(): int
    {
        $index = min($this->index, count($this->values) - 1);
        ++$this->index;

        return $this->values[$index];
    }
}

final class AlwaysNaturalDespawnRandom implements NaturalDespawnRandom
{
    public function oneIn(int $chance): bool
    {
        return true;
    }
}

final class TestNaturalSpawnEnvironment implements NaturalSpawnEnvironment
{
    public bool $loaded = true;
    public bool $stable = true;
    public NaturalSpawnMedium $medium = NaturalSpawnMedium::GROUND;
    public int $heightQueries = 0;

    /** @var array<string, int> */
    public array $worldCounts = [];

    /** @var array<string, int> */
    public array $localCounts = [];

    public function isChunkLoaded(string $worldName, ChunkPosition $chunk): bool
    {
        return $this->loaded;
    }

    public function isChunkStable(string $worldName, ChunkPosition $chunk): bool
    {
        return $this->stable;
    }

    public function dimension(string $worldName): string
    {
        return 'minecraft:overworld';
    }

    public function biome(string $worldName, Position $position): string
    {
        return 'minecraft:plains';
    }

    public function heightAt(string $worldName, float $x, float $z): float
    {
        ++$this->heightQueries;

        return 64.0;
    }

    public function medium(string $worldName, Position $position): NaturalSpawnMedium
    {
        return $this->medium;
    }

    public function lightLevel(string $worldName, Position $position): int
    {
        return 8;
    }

    public function isCollisionFree(string $worldName, EntityType $type, Position $position): bool
    {
        return true;
    }

    public function nearestPlayerDistanceSquared(string $worldName, Position $position): float
    {
        return 4_096.0;
    }

    public function worldSpawnDistanceSquared(string $worldName, Position $position): float
    {
        return 4_096.0;
    }

    public function categoryCount(string $worldName, EntityCategory $category): int
    {
        return $this->worldCounts[$category->value] ?? 0;
    }

    public function localCategoryDensity(
        string $worldName,
        ChunkPosition $chunk,
        EntityCategory $category,
    ): int {
        return $this->localCounts[$category->value] ?? 0;
    }
}
