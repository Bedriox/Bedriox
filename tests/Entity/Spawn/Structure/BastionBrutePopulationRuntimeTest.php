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

namespace Bedriox\Server\Tests\Entity\Spawn\Structure;

use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\Natural\NetherStructureLocator;
use Bedriox\Server\Entity\Spawn\Natural\NetherStructureType;
use Bedriox\Server\Entity\Spawn\Structure\BastionBrutePopulationRuntime;
use Bedriox\Server\Entity\Spawn\Structure\BastionBrutePopulationState;
use Bedriox\Server\Entity\Spawn\Structure\BastionBrutePopulationStateRepository;
use Bedriox\Server\Entity\Vanilla\Nether\PiglinBruteEntity;
use Bedriox\Server\World\ChunkPosition;
use PHPUnit\Framework\TestCase;

final class BastionBrutePopulationRuntimeTest extends TestCase
{
    public function testFinalizedOwningChunkPopulatesEachPersistentSlotOnlyOnce(): void
    {
        [$seed, $structure] = self::bastion();
        $state = new BastionBrutePopulationState();
        $store = new class implements TransientEntityPersistenceStore {
            /** @var array<string, string|null> */
            public array $payloads = [];

            public function loadTransientEntities(string $namespace): ?string
            {
                return $this->payloads[$namespace] ?? null;
            }

            public function saveTransientEntities(string $namespace, ?string $payload): void
            {
                $this->payloads[$namespace] = $payload;
            }
        };
        $repository = new BastionBrutePopulationStateRepository($store);
        $requests = [];
        $runtimeId = 0;
        $runtime = new BastionBrutePopulationRuntime(
            new NetherStructureLocator($seed),
            $state,
            static function ($request) use (&$requests, &$runtimeId): EntitySpawnOutcome {
                $requests[] = $request;

                return EntitySpawnOutcome::success(new PiglinBruteEntity(
                    EntityUuid::random(),
                    ++$runtimeId,
                    $request->worldName,
                    $request->position,
                ));
            },
            $repository,
        );
        $chunk = new ChunkPosition(
            (int) floor($structure->centerX / 16.0),
            (int) floor($structure->centerZ / 16.0),
        );

        self::assertGreaterThan(0, $runtime->populateFinalizedChunk('nether', $chunk));
        $firstCount = count($requests);
        self::assertSame(0, $runtime->populateFinalizedChunk('nether', $chunk));
        self::assertCount($firstCount, $requests);
        self::assertSame($state->encode(), BastionBrutePopulationState::decode($state->encode())->encode());
        self::assertSame($state->encode(), $repository->load()->encode());
    }

    /** @return array{int, \Bedriox\Server\Entity\Spawn\Natural\NetherStructureProvenance} */
    private static function bastion(): array
    {
        for ($seed = 0; $seed < 1_000; ++$seed) {
            $locator = new NetherStructureLocator($seed);
            for ($chunkX = -32; $chunkX <= 32; ++$chunkX) {
                for ($chunkZ = -32; $chunkZ <= 32; ++$chunkZ) {
                    foreach ($locator->intersectingChunk(new ChunkPosition($chunkX, $chunkZ)) as $structure) {
                        if ($structure->type === NetherStructureType::BASTION) {
                            return [$seed, $structure];
                        }
                    }
                }
            }
        }

        self::fail('Unable to find deterministic bastion fixture.');
    }
}
