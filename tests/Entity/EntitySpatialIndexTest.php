<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\EntitySpatialIndex;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class EntitySpatialIndexTest extends TestCase
{
    public function testNearbyIsWorldScopedNearestFirstAndStableAcrossNegativeBuckets(): void
    {
        $index = new EntitySpatialIndex();
        $positive = self::cow(3, 3.0, 64.0, 0.0);
        $negative = self::cow(1, -1.0, 64.0, 0.0);
        $sameDistance = self::cow(2, 1.0, 64.0, 0.0);
        $otherWorld = self::cow(4, 0.0, 64.0, 0.0, 'nether');
        foreach ([$positive, $negative, $sameDistance, $otherWorld] as $entity) {
            $index->add($entity);
        }

        self::assertSame(
            [1, 2, 3],
            array_map(
                static fn(AbstractEntity $entity): int => $entity->getRuntimeId(),
                $index->nearby('world', new Position(0.0, 64.0, 0.0), 4.0),
            ),
        );
        self::assertSame([$otherWorld], $index->nearby('nether', new Position(0.0, 64.0, 0.0), 1.0));
    }

    public function testMovementAndReplacementUpdateTheOwningBucket(): void
    {
        $index = new EntitySpatialIndex();
        $entity = self::cow(1, 0.0, 15.9, 0.0);
        $index->add($entity);

        $entity->moveTo('world', new Position(32.0, 16.0, -17.0), 0.0, 0.0);
        $index->reindex($entity);
        self::assertSame([], $index->nearby('world', new Position(0.0, 15.9, 0.0), 1.0));
        self::assertSame([$entity], $index->nearby('world', new Position(32.0, 16.0, -17.0), 0.0));

        $replacement = self::cow(1, -32.0, -16.0, 17.0, uniqueId: $entity->getUniqueId());
        $index->replace($replacement);
        self::assertSame([], $index->nearby('world', new Position(32.0, 16.0, -17.0), 0.0));
        self::assertSame([$replacement], $index->nearby('world', new Position(-32.0, -16.0, 17.0), 0.0));
    }

    public function testCapacityIdentityAndQueryBoundsFailDeterministically(): void
    {
        $index = new EntitySpatialIndex(1);
        $entity = self::cow(1, 0.0, 0.0, 0.0);
        $index->add($entity);

        try {
            $index->add(self::cow(1, 1.0, 0.0, 0.0));
            self::fail('Duplicate runtime identity was accepted.');
        } catch (OverflowException) {
            self::assertSame(1, $index->count());
        }

        $this->expectException(InvalidArgumentException::class);
        $index->nearby('world', new Position(0.0, 0.0, 0.0), EntitySpatialIndex::MAX_QUERY_RADIUS + 1.0);
    }

    public function testReplacementMustPreserveUniqueIdentityAndRemovalIsIdempotent(): void
    {
        $index = new EntitySpatialIndex();
        $entity = self::cow(1, 0.0, 0.0, 0.0);
        $index->add($entity);

        try {
            $index->replace(self::cow(
                1,
                1.0,
                0.0,
                0.0,
                uniqueId: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            ));
            self::fail('Replacement changed the unique identity.');
        } catch (LogicException) {
            self::assertSame([$entity], $index->nearby('world', new Position(0.0, 0.0, 0.0), 0.0));
        }

        self::assertSame($entity, $index->remove(1));
        self::assertNull($index->remove(1));
        self::assertSame(0, $index->count());
    }

    private static function cow(
        int $runtimeId,
        float $x,
        float $y,
        float $z,
        string $worldName = 'world',
        ?string $uniqueId = null,
    ): CowEntity {
        return new CowEntity(
            $uniqueId ?? sprintf('00000000-0000-4000-8000-%012d', $runtimeId),
            $runtimeId,
            $worldName,
            new Position($x, $y, $z),
        );
    }
}
