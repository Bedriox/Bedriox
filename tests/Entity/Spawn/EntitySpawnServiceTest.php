<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Entity\Spawn;

use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class EntitySpawnServiceTest extends TestCase
{
    public function testSpawnCommitsOnlyAfterAdmissionAndPublishesPostEvent(): void
    {
        $registry = new EntityRegistry();
        $events = [];
        $service = new EntitySpawnService(
            $registry,
            EntityDefinitionRegistry::baseline(),
            static fn(string $world, int $chunkX, int $chunkZ): bool => $world === 'world' && $chunkX === 0 && $chunkZ === 0,
            static fn(): bool => true,
            static function ($event) use (&$events): bool {
                $events[] = $event;
                return true;
            },
            static function ($event) use (&$events): void {
                $events[] = $event;
            },
        );

        $outcome = $service->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 1.5),
        ));

        self::assertTrue($outcome->succeeded());
        self::assertNotNull($outcome->entity);
        self::assertSame(1, $registry->count());
        self::assertCount(2, $events);
        self::assertSame($outcome->entity, $registry->getByRuntimeId(1));
        self::assertSame(SpawnCause::COMMAND, $outcome->entity->spawnOrigin());
        self::assertSame(EntityDespawnPolicy::EXPLICIT_ONLY, $outcome->entity->despawnPolicy());

        $natural = $service->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::NATURAL,
            'world',
            new Position(2.5, 64.0, 2.5),
        ));
        self::assertNotNull($natural->entity);
        self::assertSame(SpawnCause::NATURAL, $natural->entity->spawnOrigin());
        self::assertSame(EntityDespawnPolicy::NATURAL_DISTANCE, $natural->entity->despawnPolicy());
    }

    public function testCancellationAndInvalidRequestsPublishNoEntity(): void
    {
        $registry = new EntityRegistry();
        $service = new EntitySpawnService(
            $registry,
            EntityDefinitionRegistry::baseline(),
            static fn(): bool => true,
            static fn(): bool => true,
            static fn(): bool => false,
        );
        $cancelled = $service->spawn(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::PLUGIN,
            'world',
            new Position(0.0, 64.0, 0.0),
        ));
        self::assertSame('cancelled', $cancelled->failure);
        self::assertSame(0, $registry->count());

        $unsupported = $service->spawn(new EntitySpawnRequest(
            new CustomEntityType('example:missing'),
            SpawnCause::PLUGIN,
            'world',
            new Position(0.0, 64.0, 0.0),
        ));
        self::assertSame('unsupported_type', $unsupported->failure);
        self::assertSame(0, $registry->count());
    }

    public function testFailedLifecycleAdmissionSuppressesTheSpawnedPostEvent(): void
    {
        $registry = new EntityRegistry();
        $order = [];
        $service = new EntitySpawnService(
            $registry,
            EntityDefinitionRegistry::baseline(),
            afterSpawn: static function () use (&$order): void {
                $order[] = 'post';
            },
            admitSpawn: static function () use (&$order): bool {
                $order[] = 'lifecycle';

                return false;
            },
        );

        $outcome = $service->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::PLUGIN,
            'world',
            new Position(0.0, 64.0, 0.0),
        ));

        self::assertSame('admission_rejected', $outcome->failure);
        self::assertSame(['lifecycle'], $order);
        self::assertSame(0, $registry->count());
    }
}
