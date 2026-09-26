<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class VanillaAiBehaviorTest extends TestCase
{
    public function testDefinitionsAreSharedButRuntimeMemoryAndMotionArePerEntity(): void
    {
        self::assertSame(VanillaAiBehaviors::cow(), VanillaAiBehaviors::cow());
        $first = new CowEntity(
            '00000000-0000-4000-8000-000000000001',
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $second = new CowEntity(
            '00000000-0000-4000-8000-000000000002',
            2,
            'world',
            new Position(2.0, 64.0, 0.0),
        );
        $world = new class implements AiWorldView {
            public ?float $distance = 1.0;

            public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
            {
                return [];
            }

            public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
            {
                return $this->distance;
            }
        };

        for ($tick = 1; $tick <= 45; ++$tick) {
            $context = new AiTickContext($tick, $world);
            $first->tickAi($context, true);
            $second->tickAi($context, true);
        }

        self::assertGreaterThan(0.0, $first->getMotion()->lengthSquared());
        self::assertGreaterThan(0.0, $second->getMotion()->lengthSquared());
        self::assertNotEquals($first->getMotion(), $second->getMotion());
        self::assertNotSame($first->aiRuntime()->memory(), $second->aiRuntime()->memory());
    }
}
