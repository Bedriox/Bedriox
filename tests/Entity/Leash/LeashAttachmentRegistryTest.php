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

namespace Bedriox\Server\Tests\Entity\Leash;

use Bedriox\Api\Entity\Value\LeashHolderType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Leash\LeashAttachmentRegistry;
use Bedriox\Server\Entity\Leash\ResolvedLeashHolder;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\Misc\LeashKnotEntity;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LeashAttachmentRegistryTest extends TestCase
{
    public function testFenceKnotLookupTransferBoundsAndDuplicateIdentityAreAuthoritative(): void
    {
        [$registry, $entities, $players] = self::registry();
        self::assertSame(
            88,
            BedrockDataSet::bundled()->entityTypeRegistry()
                ->definitionForIdentifier('minecraft:leash_knot')
                ->networkRuntimeId(),
        );
        $holder = self::player(
            'holder',
            25,
            new PlayerIdentity(EntityUuid::random(), 'Holder'),
            new Position(0.5, 64.0, 0.5),
        );
        $players->add($holder);
        $near = self::spawnLlama($entities, new Position(3.5, 64.0, 0.5));
        $far = self::spawnLlama($entities, new Position(9.0, 64.0, 0.5));
        self::assertTrue($registry->attachToPlayer($near, $holder));
        self::assertTrue($registry->attachToPlayer($far, $holder));

        $fence = new BlockPosition(0, 64, 0);
        $knot = $entities->spawn(static fn(string $uuid, int $runtimeId): LeashKnotEntity => new LeashKnotEntity(
            $uuid,
            $runtimeId,
            'world',
            LeashKnotEntity::anchorPosition($fence),
        ));
        self::assertInstanceOf(LeashKnotEntity::class, $knot);
        self::assertSame($knot, $registry->knotAt('world', $fence));
        self::assertNull($registry->knotAt('world', new BlockPosition(1, 64, 0)));
        self::assertSame([$near], $registry->transferableFromPlayer($holder, $knot->internalPosition()));
        self::assertTrue($registry->attachToEntity($near, $knot));
        self::assertSame([$near], $registry->attachmentsToEntity($knot));
        self::assertSame([], $registry->transferableFromPlayer($holder, $knot->internalPosition()));
        self::assertSame([$knot], $registry->knots());
    }

    public function testEntityAttachmentResolvesRefreshesForLateViewerAndRejectsCycles(): void
    {
        [$registry, $entities] = self::registry();
        $leader = self::spawnLlama($entities, new Position(1.0, 64.0, 1.0));
        $follower = self::spawnLlama($entities, new Position(2.0, 64.0, 1.0));

        self::assertTrue($registry->attachToEntity($follower, $leader));
        self::assertSame(LeashHolderType::ENTITY, $follower->getLeashHolderType());
        self::assertSame($leader->getUniqueId(), $follower->getLeashHolderUniqueId());
        self::assertFalse($registry->attachToEntity($leader, $follower));
        self::assertFalse($registry->attachToEntity($leader, $leader));

        $persisted = $follower->persistenceData();
        $restored = new LlamaEntity(
            $follower->getUniqueId(),
            900,
            'world',
            new Position(2.0, 64.0, 1.0),
        );
        $restored->restorePersistenceState(null, $follower->persistenceSchemaVersion(), $persisted);
        self::assertNull($restored->getLeashHolderRuntimeId());
        self::assertSame(LeashHolderType::ENTITY, $restored->getLeashHolderType());

        $resolved = self::requireResolved($registry->resolve($restored));
        $registry->refreshRuntimeIdentity($restored, $resolved);
        self::assertSame($leader->getRuntimeId(), $restored->getLeashHolderRuntimeId());

        $metadata = (new BedrockLivingActorProjector())->metadata($restored);
        self::assertSame($leader->getRuntimeId(), self::metadataLong($metadata, 37));
    }

    public function testPlayerReconnectRebindsRuntimeIdentityWithoutChangingPersistentOwner(): void
    {
        [$registry, $entities, $players] = self::registry();
        $animal = self::spawnLlama($entities, new Position(1.0, 64.0, 1.0));
        $identity = new PlayerIdentity(EntityUuid::random(), 'Holder');
        $first = self::player('first', 50, $identity, new Position(1.0, 64.0, 2.0));
        $players->add($first);

        self::assertTrue($registry->attachToPlayer($animal, $first));
        self::assertSame(LeashHolderType::PLAYER, $animal->getLeashHolderType());
        self::assertSame(50, $animal->getLeashHolderRuntimeId());

        $players->remove('first');
        self::assertUnresolved($registry, $animal);

        $second = self::player('second', 75, $identity, new Position(1.0, 64.0, 2.0));
        $players->add($second);
        $resolved = self::requireResolved($registry->resolve($animal));
        $registry->refreshRuntimeIdentity($animal, $resolved);
        self::assertSame($identity->uuid, $animal->getLeashHolderUniqueId());
        self::assertSame(75, $animal->getLeashHolderRuntimeId());
    }

    public function testUnavailableEntityAndRepeatedDetachRemainBenign(): void
    {
        [$registry, $entities] = self::registry();
        $leader = self::spawnLlama($entities, new Position(1.0, 64.0, 1.0));
        $follower = self::spawnLlama($entities, new Position(2.0, 64.0, 1.0));
        self::assertTrue($registry->attachToEntity($follower, $leader));

        $entities->remove($leader->getRuntimeId());
        self::assertNull($registry->resolve($follower));
        self::assertTrue($registry->detach($follower));
        self::assertFalse($registry->detach($follower));
        self::assertFalse($follower->isLeashed());
    }

    public function testAttachmentRejectsDeadCrossWorldAndOutOfRangeHolders(): void
    {
        [$registry, $entities] = self::registry();
        $animal = self::spawnLlama($entities, new Position(0.0, 64.0, 0.0));
        $far = self::spawnLlama($entities, new Position(20.0, 64.0, 0.0));
        $otherWorld = $entities->spawn(static fn(string $uuid, int $runtimeId): LlamaEntity => new LlamaEntity(
            $uuid,
            $runtimeId,
            'other',
            new Position(0.0, 64.0, 0.0),
        ));
        $dead = self::spawnLlama($entities, new Position(1.0, 64.0, 0.0));
        $dead->damage($dead->getHealth());

        self::assertFalse($registry->attachToEntity($animal, $far));
        self::assertFalse($registry->attachToEntity($animal, $otherWorld));
        self::assertFalse($registry->attachToEntity($animal, $dead));
        self::assertFalse($animal->isLeashed());
    }

    public function testMalformedHolderStateFailsClosed(): void
    {
        [, $entities] = self::registry();
        $animal = self::spawnLlama($entities, new Position(0.0, 64.0, 0.0));

        $this->expectException(InvalidArgumentException::class);
        $animal->setLeashHolder(EntityUuid::random(), null, LeashHolderType::ENTITY);
    }

    public function testMalformedPersistedHolderTypeFailsClosed(): void
    {
        [, $entities] = self::registry();
        $animal = self::spawnLlama($entities, new Position(0.0, 64.0, 0.0));
        $animal->setLeashHolder(EntityUuid::random(), 100, LeashHolderType::ENTITY);
        $data = json_decode($animal->persistenceData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['leashHolderType'] = 'fence';

        $restored = new LlamaEntity(EntityUuid::random(), 901, 'world', new Position(0.0, 64.0, 0.0));
        $this->expectException(InvalidArgumentException::class);
        $restored->restorePersistenceState(
            null,
            $animal->persistenceSchemaVersion(),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{LeashAttachmentRegistry, EntityRegistry, PlayerRegistry} */
    private static function registry(): array
    {
        $players = new PlayerRegistry(8);
        $entities = new EntityRegistry(64, 2_000_000_000);

        return [new LeashAttachmentRegistry($players, $entities), $entities, $players];
    }

    private static function spawnLlama(EntityRegistry $registry, Position $position): LlamaEntity
    {
        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): LlamaEntity => new LlamaEntity(
            $uuid,
            $runtimeId,
            'world',
            $position,
        ));
        self::assertInstanceOf(LlamaEntity::class, $entity);

        return $entity;
    }

    private static function player(
        string $session,
        int $runtimeId,
        PlayerIdentity $identity,
        Position $position,
    ): Player {
        return new Player($session, $runtimeId, $identity, $position, 8, 0, 64.0);
    }

    private static function requireResolved(?ResolvedLeashHolder $holder): ResolvedLeashHolder
    {
        if ($holder === null) {
            self::fail('Expected the leash holder to resolve.');
        }

        return $holder;
    }

    private static function assertUnresolved(
        LeashAttachmentRegistry $registry,
        LlamaEntity $animal,
    ): void {
        self::assertNull($registry->resolve($animal));
    }

    /** @param list<ActorMetadata> $metadata */
    private static function metadataLong(array $metadata, int $id): int
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id) {
                self::assertIsInt($entry->value);

                return $entry->value;
            }
        }
        self::fail('Metadata entry was not found.');
    }
}
