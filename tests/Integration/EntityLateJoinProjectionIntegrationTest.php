<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class EntityLateJoinProjectionIntegrationTest extends TestCase
{
    public function testLateJoiningPlayerReceivesExistingLivingActors(): void
    {
        $simulation = new WorldSimulation(
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('first-session', 'first-identity', 'First')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(2.0, 64.0, 2.0),
        ));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->join('second-session', 'second-identity', 'Second')));
        $events = $simulation->tick()->events;
        $actors = array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorSpawned,
        ));

        self::assertCount(1, $actors);
        self::assertSame($spawn->entity, $actors[0]->entity);
        self::assertSame(['second-session'], $actors[0]->recipientSessionIds);
        self::assertTrue($actors[0]->noAi);
    }
}
