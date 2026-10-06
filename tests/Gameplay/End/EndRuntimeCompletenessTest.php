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

namespace Bedriox\Server\Tests\Gameplay\End;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Entity\Vanilla\End\EnderDragonEntity;
use Bedriox\Server\Gameplay\End\EndArenaLayout;
use Bedriox\Server\Gameplay\End\EnderDragonPart;
use Bedriox\Server\Gameplay\End\EnderDragonPartProjection;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\EndWorldGenerator;
use PHPUnit\Framework\TestCase;

final class EndRuntimeCompletenessTest extends TestCase
{
    public function testInitialArenaTicketCoversEveryCrystalPillarChunk(): void
    {
        $chunks = EndArenaLayout::requiredChunks();

        self::assertCount(11, $chunks);
        self::assertCount(11, array_unique(array_map(static fn(ChunkPosition $chunk): string => $chunk->key(), $chunks)));
        foreach (EndArenaLayout::crystalPositions() as $crystal) {
            $expected = (new ChunkPosition(
                (int) floor($crystal->x / 16.0),
                (int) floor($crystal->z / 16.0),
            ))->key();
            self::assertContains($expected, array_map(static fn(ChunkPosition $chunk): string => $chunk->key(), $chunks));
        }
    }

    public function testDragonPartsRemainServerSideWhileTheClientReceivesOneDragonActor(): void
    {
        $dragon = new EnderDragonEntity(
            '11111111-1111-4111-8111-111111111111',
            17,
            'world',
            new Position(10.0, 90.0, -4.0),
            new EntityMotion(0.2, 0.0, 0.1),
            yaw: 90.0,
        );
        $parts = EnderDragonPartProjection::forDragon($dragon);

        self::assertCount(4, $parts);
        self::assertCount(4, array_unique(array_map(static fn($part): int => $part->runtimeActorId, $parts)));
        self::assertSame(EnderDragonPart::HEAD, $parts[0]->part);
        self::assertEqualsWithDelta(4.0, $parts[0]->position->x, 0.000_001);
        foreach ($parts as $part) {
            self::assertSame(17, EnderDragonPartProjection::parentRuntimeId($part->runtimeActorId));
            self::assertSame($part->part, EnderDragonPartProjection::resolve($dragon, $part->runtimeActorId));
        }

        $encoder = new BedrockWorldEventPacketEncoder();
        $spawn = $encoder->encode(new EntityActorSpawned($dragon, ['viewer']), []);
        self::assertCount(1, array_filter($spawn, static fn($directed): bool => $directed->packet instanceof AddActorPacket));
        $movement = $encoder->encode(new EntityActorMoved($dragon, 20, ['viewer']), []);
        self::assertCount(1, array_filter($movement, static fn($directed): bool => $directed->packet instanceof MoveActorAbsolutePacket));
        $removed = $encoder->encode(new EntityActorRemoved($dragon, ['viewer']), []);
        self::assertCount(1, array_filter($removed, static fn($directed): bool => $directed->packet instanceof RemoveActorPacket));
    }

    public function testEndCrystalOwnedPresentationStateRoundTripsExactly(): void
    {
        $crystal = new EndCrystalEntity(
            '22222222-2222-4222-8222-222222222222',
            18,
            'world',
            new Position(42.5, 90.0, 0.5),
        );
        $crystal->configureEncounterState(true, true, new Position(0.0, 128.0, 0.0), true);
        $restored = new EndCrystalEntity(
            '22222222-2222-4222-8222-222222222222',
            19,
            'world',
            new Position(42.5, 90.0, 0.5),
        );
        $restored->restorePersistenceState(
            $crystal->persistenceVariant(),
            $crystal->persistenceSchemaVersion(),
            $crystal->persistenceData(),
        );

        self::assertTrue($restored->isEncounterOwned());
        self::assertTrue($restored->showsBase());
        self::assertTrue($restored->isInvulnerable());
        self::assertEquals(new Position(0.0, 128.0, 0.0), $restored->beamTarget());
    }

    public function testEndShipIsDeterministicAndAttachedToItsCityPlacement(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $position = new ChunkPosition(-108, -79);
        $first = (new EndWorldGenerator(42, $states))->generate($position);
        $same = (new EndWorldGenerator(42, $states))->generate($position);

        self::assertSame($this->fingerprint($first), $this->fingerprint($same));
        self::assertTrue($this->contains($first, $states, 'minecraft:dragon_head'));
    }

    private function contains(\Bedriox\Server\World\Chunk $chunk, BlockStateRegistry $states, string $identifier): bool
    {
        foreach ($chunk->populatedSections() as $section) {
            foreach ($section->palette() as $state) {
                if ($states->state($state)->identifier() === $identifier) {
                    return true;
                }
            }
        }

        return false;
    }

    private function fingerprint(\Bedriox\Server\World\Chunk $chunk): string
    {
        $values = [];
        foreach ($chunk->populatedSections() as $section) {
            $values[] = $section->sectionY . ':' . implode(',', array_map(
                static fn($state): int => $state->value,
                $section->palette(),
            ));
            $values[] = hash('sha256', $section->blockStorageLayers()[0]->paletteIndices());
        }

        return hash('sha256', implode('|', $values));
    }
}
