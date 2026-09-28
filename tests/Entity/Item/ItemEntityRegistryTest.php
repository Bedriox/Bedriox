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

namespace Bedriox\Server\Tests\Entity\Item;

use Bedriox\Server\Entity\Item\DroppedItemEntity;
use Bedriox\Server\Entity\Item\ItemEntityMotion;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class ItemEntityRegistryTest extends TestCase
{
    public function testSpawnsBoundedEntitiesWithStableMonotonicIds(): void
    {
        $registry = new ItemEntityRegistry(2);
        $first = $registry->spawn(self::stack(3), new Position(1.0, 70.0, 2.0));
        $second = $registry->spawn(self::stack(1), new Position(2.0, 70.0, 2.0));

        self::assertSame(1, $first->uniqueEntityId);
        self::assertSame(1, $first->runtimeEntityId);
        self::assertSame(2, $second->uniqueEntityId);
        self::assertSame(2, $registry->count());

        $this->expectException(OverflowException::class);
        $registry->spawn(self::stack(1), new Position(3.0, 70.0, 2.0));
    }

    public function testReportsRemainingCapacityAndRemovesExactlyTheRequestedEntity(): void
    {
        $registry = new ItemEntityRegistry(2);
        $first = $registry->spawn(self::stack(1), new Position(1.0, 70.0, 2.0));
        $second = $registry->spawn(self::stack(1), new Position(2.0, 70.0, 2.0));

        self::assertSame(0, $registry->remainingCapacity());
        self::assertSame($first, $registry->remove($first->runtimeEntityId));
        self::assertNull($registry->remove($first->runtimeEntityId));
        self::assertSame(1, $registry->remainingCapacity());
        self::assertSame($second, $registry->get($second->runtimeEntityId));
    }

    public function testTicksWithPmmpItemGravityDragAndPickupDelay(): void
    {
        $registry = new ItemEntityRegistry();
        $entity = $registry->spawn(
            self::stack(2),
            new Position(10.0, 20.0, 30.0),
            new ItemEntityMotion(1.0, 0.5, -2.0),
            2,
        );

        $result = $registry->tick();
        $updated = $registry->get($entity->runtimeEntityId);
        self::assertNotNull($updated);
        self::assertSame([$updated], $result->updated);
        self::assertEqualsWithDelta(0.98, $updated->motion->x, 0.000001);
        self::assertEqualsWithDelta(0.45, $updated->motion->y, 0.000001);
        self::assertEqualsWithDelta(-1.96, $updated->motion->z, 0.000001);
        self::assertEqualsWithDelta(10.98, $updated->position->x, 0.000001);
        self::assertEqualsWithDelta(20.45, $updated->position->y, 0.000001);
        self::assertEqualsWithDelta(28.04, $updated->position->z, 0.000001);
        self::assertSame(1, $updated->pickupDelayTicks);
        self::assertSame(1, $updated->ageTicks);
        self::assertFalse($updated->canBePickedUp());

        $registry->tick();
        self::assertTrue($registry->get($entity->runtimeEntityId)?->canBePickedUp());
    }

    public function testNearbyCandidatesAreEligibleBoundedAndNearestFirst(): void
    {
        $registry = new ItemEntityRegistry();
        $far = $registry->spawn(self::stack(1), new Position(3.0, 0.0, 0.0), despawnAfterTicks: null);
        $delayed = $registry->spawn(self::stack(1), new Position(0.5, 0.0, 0.0), pickupDelayTicks: 10);
        $near = $registry->spawn(self::stack(1), new Position(1.0, 0.0, 0.0));
        $sameDistance = $registry->spawn(self::stack(1), new Position(-1.0, 0.0, 0.0));

        self::assertSame(
            [$near->runtimeEntityId, $sameDistance->runtimeEntityId, $far->runtimeEntityId],
            array_map(
                static fn(DroppedItemEntity $entity): int => $entity->runtimeEntityId,
                $registry->nearbyPickupCandidates(new Position(0.0, 0.0, 0.0), 4.0),
            ),
        );
        self::assertSame([], $registry->nearbyPickupCandidates(new Position(0.0, 0.0, 0.0), 0.25));
        self::assertNotNull($registry->get($delayed->runtimeEntityId));
    }

    public function testPartialAndFullPickupRetainIdentityAndRemoveExactlyOnce(): void
    {
        $registry = new ItemEntityRegistry();
        $entity = $registry->spawn(self::stack(12, 41), new Position(0.0, 0.0, 0.0));

        $partial = $registry->pickup($entity->runtimeEntityId, 5);
        self::assertNotNull($partial);
        self::assertFalse($partial->removed());
        self::assertSame(5, $partial->pickedUp->count);
        self::assertSame(41, $partial->pickedUp->damage);
        self::assertSame(7, $partial->remaining?->count);
        self::assertSame($entity->uniqueEntityId, $registry->get($entity->runtimeEntityId)?->uniqueEntityId);

        $full = $registry->pickup($entity->runtimeEntityId, 7);
        self::assertNotNull($full);
        self::assertTrue($full->removed());
        self::assertNull($registry->get($entity->runtimeEntityId));
        self::assertNull($registry->pickup($entity->runtimeEntityId, 1));
    }

    public function testDelayedItemsCannotBePickedUpAndInvalidCountsAreRejected(): void
    {
        $registry = new ItemEntityRegistry();
        $delayed = $registry->spawn(self::stack(2), new Position(0.0, 0.0, 0.0), pickupDelayTicks: 1);
        self::assertNull($registry->pickup($delayed->runtimeEntityId, 1));
        $registry->tick();

        $this->expectException(InvalidArgumentException::class);
        $registry->pickup($delayed->runtimeEntityId, 3);
    }

    public function testDespawnsAtConfiguredAgeAndNeverDespawnRemains(): void
    {
        $registry = new ItemEntityRegistry();
        $expiring = $registry->spawn(self::stack(1), new Position(0.0, 0.0, 0.0), despawnAfterTicks: 3);
        $persistent = $registry->spawn(self::stack(1), new Position(1.0, 0.0, 0.0), despawnAfterTicks: null);

        self::assertSame([], $registry->tick(2)->despawned);
        $result = $registry->tick();
        self::assertSame([$expiring->runtimeEntityId], array_map(
            static fn(DroppedItemEntity $entity): int => $entity->runtimeEntityId,
            $result->despawned,
        ));
        self::assertNull($registry->get($expiring->runtimeEntityId));
        self::assertSame(3, $registry->get($persistent->runtimeEntityId)?->ageTicks);
    }

    public function testRejectsUnboundedTickAndQueryInput(): void
    {
        $registry = new ItemEntityRegistry();
        $registry->spawn(self::stack(1), new Position(0.0, 0.0, 0.0));
        $rejected = 0;
        foreach ([0, ItemEntityRegistry::MAX_TICK_ADVANCE + 1] as $ticks) {
            try {
                $registry->tick($ticks);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }
        try {
            $registry->nearbyPickupCandidates(new Position(0.0, 0.0, 0.0), 33.0);
        } catch (InvalidArgumentException) {
            ++$rejected;
        }

        self::assertSame(3, $rejected);
    }

    private static function stack(int $count, int $damage = 0): InventoryStack
    {
        return new InventoryStack('minecraft:diamond_pickaxe', $count, 1, damage: $damage);
    }
}
