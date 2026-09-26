<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use LogicException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class EntityRegistryTest extends TestCase
{
    public function testSpawnOwnsMonotonicIdentityLookupCapacityAndCounts(): void
    {
        $registry = new EntityRegistry(2, 41);
        $cow = $registry->spawn(
            static fn(string $uuid, int $runtimeId): CowEntity =>
                new CowEntity($uuid, $runtimeId, 'world', new Position(0.0, 64.0, 0.0)),
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        );
        $zombie = $registry->spawn(
            static fn(string $uuid, int $runtimeId): ZombieEntity =>
                new ZombieEntity($uuid, $runtimeId, 'world', new Position(2.0, 64.0, 0.0)),
        );

        self::assertSame(41, $cow->getRuntimeId());
        self::assertSame(42, $zombie->getRuntimeId());
        self::assertSame($cow, $registry->getByRuntimeId(41));
        self::assertSame($cow, $registry->getByUniqueId('AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'));
        self::assertSame(1, $registry->countByCategory(EntityCategory::ANIMAL));
        self::assertSame(1, $registry->countByCategory(EntityCategory::MONSTER));
        self::assertSame(1, $registry->countByType(VanillaEntityType::COW));
        self::assertSame(1, $registry->countByType(VanillaEntityType::ZOMBIE));
        self::assertSame(0, $registry->remainingCapacity());

        $this->expectException(OverflowException::class);
        $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity =>
            new CowEntity($uuid, $runtimeId, 'world', new Position(4.0, 64.0, 0.0)));
    }

    public function testMovementReplacementRemovalAndSpatialFiltersRemainConsistent(): void
    {
        $registry = new EntityRegistry();
        $cow = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity =>
            new CowEntity($uuid, $runtimeId, 'world', new Position(0.0, 64.0, 0.0)));
        $zombie = $registry->spawn(static fn(string $uuid, int $runtimeId): ZombieEntity =>
            new ZombieEntity($uuid, $runtimeId, 'world', new Position(1.0, 64.0, 0.0)));

        self::assertSame([$cow], $registry->nearby(
            'world',
            new Position(0.0, 64.0, 0.0),
            2.0,
            category: EntityCategory::ANIMAL,
        ));
        self::assertSame([$zombie], $registry->nearby(
            'world',
            new Position(0.0, 64.0, 0.0),
            2.0,
            type: VanillaEntityType::ZOMBIE,
        ));

        $registry->move($cow->getRuntimeId(), 'other', new Position(-32.0, 80.0, 16.0), 450.0, -30.0);
        self::assertSame([$zombie], $registry->nearby('world', new Position(0.0, 64.0, 0.0), 2.0));
        self::assertSame([$cow], $registry->nearby('other', new Position(-32.0, 80.0, 16.0), 0.0));
        self::assertSame(90.0, $cow->getYaw());

        $replacement = new CowEntity(
            $cow->getUniqueId(),
            $cow->getRuntimeId(),
            'other',
            new Position(-48.0, 80.0, 16.0),
        );
        $registry->replace($replacement);
        self::assertTrue($cow->isRemoved());
        self::assertSame([$replacement], $registry->nearby('other', new Position(-48.0, 80.0, 16.0), 0.0));

        self::assertSame($zombie, $registry->remove($zombie->getRuntimeId()));
        self::assertTrue($zombie->isRemoved());
        self::assertNull($registry->remove($zombie->getRuntimeId()));
        self::assertSame(0, $registry->countByCategory(EntityCategory::MONSTER));
        self::assertSame(0, $registry->countByType(VanillaEntityType::ZOMBIE));
    }

    public function testFactoryIdentityFailureConsumesIdWithoutMutatingRegistry(): void
    {
        $registry = new EntityRegistry(2, 10);
        try {
            $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity =>
                new CowEntity($uuid, $runtimeId + 1, 'world', new Position(0.0, 64.0, 0.0)));
            self::fail('Factory changed the allocated runtime ID.');
        } catch (LogicException) {
            self::assertSame(0, $registry->count());
        }

        $entity = $registry->spawn(static fn(string $uuid, int $runtimeId): CowEntity =>
            new CowEntity($uuid, $runtimeId, 'world', new Position(0.0, 64.0, 0.0)));
        self::assertSame(11, $entity->getRuntimeId());
    }

    public function testDuplicateUuidAndTypeChangingReplacementAreRejected(): void
    {
        $registry = new EntityRegistry();
        $uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $cow = $registry->spawn(
            static fn(string $uniqueId, int $runtimeId): CowEntity =>
                new CowEntity($uniqueId, $runtimeId, 'world', new Position(0.0, 64.0, 0.0)),
            $uuid,
        );

        try {
            $registry->spawn(
                static fn(string $uniqueId, int $runtimeId): CowEntity =>
                    new CowEntity($uniqueId, $runtimeId, 'world', new Position(1.0, 64.0, 0.0)),
                $uuid,
            );
            self::fail('Duplicate UUID was admitted.');
        } catch (LogicException) {
            self::assertSame(1, $registry->count());
        }

        $this->expectException(LogicException::class);
        $registry->replace(new ZombieEntity(
            $cow->getUniqueId(),
            $cow->getRuntimeId(),
            'world',
            new Position(0.0, 64.0, 0.0),
        ));
    }
}
