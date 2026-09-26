<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class IndexedAiWorldViewTest extends TestCase
{
    public function testActivationDistanceDoesNotPerformTheHostileLineOfSightQuery(): void
    {
        $entities = new EntityRegistry();
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $lineOfSightQueries = 0;
        $view = new IndexedAiWorldView(
            $entities,
            static fn(): array => [new AiPlayerSnapshot(
                'player-one',
                'world',
                new Position(1.0, 64.0, 0.0),
            )],
            static function (AbstractMobEntity $_entity, AiPlayerSnapshot $_player) use (&$lineOfSightQueries): bool {
                ++$lineOfSightQueries;

                return false;
            },
        );

        self::assertSame(1.0, $view->nearestPlayerDistanceSquared($zombie));
        self::assertSame(0, $lineOfSightQueries);
        self::assertNull($view->nearestPlayer($zombie, 8.0));
        self::assertSame(1, $lineOfSightQueries);
    }
}
