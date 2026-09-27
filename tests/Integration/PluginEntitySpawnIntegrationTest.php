<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\CustomMobBehavior;
use Bedriox\Api\Entity\CustomMobDefinition;
use Bedriox\Api\Entity\CustomMobSpawnContext;
use Bedriox\Api\Entity\CustomMobTickContext;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityDespawnedEvent;
use Bedriox\Api\Event\Entity\EntitySpawnedEvent;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\Persistence\EntityChunkSnapshot;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransfer;
use Bedriox\Server\Entity\Persistence\EntityOwnershipTransferResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\PluginMobEntity;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\OwnedEntityRegistrar;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginEntityDefinitionBridge;
use Bedriox\Server\Plugin\PluginEntityLifecycleBridge;
use Bedriox\Server\Plugin\PluginEntityRegistrar;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\ChunkUnloadManager;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PluginEntitySpawnIntegrationTest extends TestCase
{
    public function testOwnerScopedApiRegistersAndSpawnsAnAuthoritativePluginMob(): void
    {
        $control = new EntitySpawnPluginRuntimeControl(['Example', 'Other']);
        $ownership = new PluginOwnershipRegistry();
        $registrar = new PluginEntityRegistrar(
            $control,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            $ownership,
        );
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        $lifecycle = new PluginEntityLifecycleBridge($registrar);
        $simulation = new WorldSimulation(
            entityAiEnabled: true,
            entityDefinitions: $definitions,
            pluginEntityLifecycle: $lifecycle,
        );
        $behavior = null;
        $type = new CustomEntityType('example:sentinel');
        $owned = new OwnedEntityRegistrar(
            'Example',
            $registrar,
            spawner: static function (
                CustomEntityType $spawnType,
                ApiPosition $position,
                float $yaw,
                float $pitch,
            ) use ($simulation): bool {
                return $simulation->spawnEntity(new EntitySpawnRequest(
                    $spawnType,
                    SpawnCause::PLUGIN,
                    'world',
                    new Position($position->x, $position->y, $position->z),
                    $yaw,
                    $pitch,
                ))->succeeded();
            },
        );
        $owned->register(new CustomMobDefinition(
            $type,
            VanillaEntityType::ZOMBIE,
            EntityCategory::MONSTER,
            0.6,
            1.95,
            30.0,
            static function () use (&$behavior): CustomMobBehavior {
                return $behavior = new EntitySpawnIntegrationBehavior();
            },
        ));

        $owned->spawn($type, new ApiPosition(2.5, 64.0, 3.5), 135.0, -15.0);
        $entities = $simulation->entityRuntime()->registry()->all();
        self::assertCount(1, $entities);
        $entity = $entities[0];
        self::assertInstanceOf(PluginMobEntity::class, $entity);
        self::assertSame('Example', $entity->pluginOwner());
        self::assertSame('example:sentinel', $entity->getType()->identifier());
        self::assertSame(135.0, $entity->getYaw());
        self::assertSame(-15.0, $entity->getPitch());
        self::assertInstanceOf(EntitySpawnIntegrationBehavior::class, $behavior);
        self::assertSame(1, $behavior->spawnCalls);
        self::assertSame(SpawnCause::PLUGIN, $behavior->spawnCause);

        $events = $simulation->tick()->events;
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorSpawned,
        ));
        self::assertSame(1, $behavior->tickCalls);
        self::assertSame(0, $behavior->aiTickCalls);

        $this->expectException(PluginException::class);
        (new OwnedEntityRegistrar(
            'Other',
            $registrar,
            spawner: static fn(
                CustomEntityType $_type,
                ApiPosition $_position,
                float $_yaw,
                float $_pitch,
            ): bool => true,
        ))->spawn($type, new ApiPosition(0.5, 64.0, 0.5));
    }

    public function testPersistenceActivationAndUnloadPublishSymmetricPluginLifecycle(): void
    {
        $control = new EntitySpawnPluginRuntimeControl(['Example', 'Observer']);
        $ownership = new PluginOwnershipRegistry();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $registrar = new PluginEntityRegistrar($control, $execution, $actions, $ownership);
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        $lifecycle = new PluginEntityLifecycleBridge($registrar);
        $events = new EventDispatcher($control, $execution, $actions, $ownership);
        $notifications = [];
        $events->register('Observer', EntitySpawnedEvent::class, static function (
            EntitySpawnedEvent $event,
        ) use (&$notifications): void {
            $behavior = $event->entity instanceof PluginMobEntity ? $event->entity->customBehavior() : null;
            $notifications[] = [
                'spawned',
                $event->cause->value,
                $behavior instanceof PersistentEntityLifecycleBehavior ? $behavior->spawnCalls : -1,
            ];
        });
        $events->register('Observer', EntityDespawnedEvent::class, static function (
            EntityDespawnedEvent $event,
        ) use (&$notifications): void {
            $behavior = $event->entity instanceof PluginMobEntity ? $event->entity->customBehavior() : null;
            $notifications[] = [
                'despawned',
                $event->reason,
                $behavior instanceof PersistentEntityLifecycleBehavior ? $behavior->despawnCalls : -1,
            ];
        });

        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 12345),
            new FlatWorldGenerator($palette),
            new ChunkRepository(8),
            chunkUnloads: new ChunkUnloadManager(graceNanoseconds: 0),
        );
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            pluginEvents: new PluginGameplayEventBridge($events),
            entityDefinitions: $definitions,
            pluginEntityLifecycle: $lifecycle,
            entityAiEnabled: false,
        );
        $store = new PluginEntityPersistenceStore();
        $simulation->enableEntityPersistence($store, $definitions);
        $chunk = new ChunkPosition(0, 0);
        $world->chunk($chunk);
        $type = new CustomEntityType('example:persistent');
        (new OwnedEntityRegistrar('Example', $registrar))->register(new CustomMobDefinition(
            $type,
            VanillaEntityType::ZOMBIE,
            EntityCategory::MONSTER,
            0.6,
            1.95,
            20.0,
            static fn(): CustomMobBehavior => new PersistentEntityLifecycleBehavior(),
        ));

        self::assertTrue($simulation->spawnEntity(new EntitySpawnRequest(
            $type,
            SpawnCause::PLUGIN,
            'world',
            new Position(1.5, 64.0, 1.5),
        ))->succeeded());
        self::assertSame([['spawned', SpawnCause::PLUGIN->value, 1]], $notifications);

        self::assertSame(1, $world->processChunkUnloads(1, 10_000)->evicted);
        self::assertSame(0, $simulation->entityRuntime()->registry()->count());
        self::assertSame([
            ['spawned', SpawnCause::PLUGIN->value, 1],
            ['despawned', 'chunk_unload', 1],
        ], $notifications);

        $world->chunk($chunk);
        self::assertSame(1, $simulation->entityRuntime()->registry()->count());
        self::assertSame([
            ['spawned', SpawnCause::PLUGIN->value, 1],
            ['despawned', 'chunk_unload', 1],
            ['spawned', SpawnCause::CHUNK_LOAD->value, 1],
        ], $notifications);
    }
}

final class EntitySpawnIntegrationBehavior extends CustomMobBehavior
{
    public int $spawnCalls = 0;
    public int $tickCalls = 0;
    public int $aiTickCalls = 0;
    public ?SpawnCause $spawnCause = null;

    public function onSpawn(CustomMobSpawnContext $context): void
    {
        ++$this->spawnCalls;
        $this->spawnCause = $context->cause;
    }

    public function onTick(CustomMobTickContext $context): void
    {
        ++$this->tickCalls;
    }

    public function onAiTick(CustomMobTickContext $context): void
    {
        ++$this->aiTickCalls;
    }
}

final class PersistentEntityLifecycleBehavior extends CustomMobBehavior
{
    public int $spawnCalls = 0;
    public int $despawnCalls = 0;

    public function onSpawn(CustomMobSpawnContext $context): void
    {
        ++$this->spawnCalls;
    }

    public function onDespawn(\Bedriox\Api\Entity\CustomMobDespawnContext $context): void
    {
        ++$this->despawnCalls;
    }
}

final class EntitySpawnPluginRuntimeControl implements PluginRuntimeControl
{
    /** @param list<string> $enabled */
    public function __construct(private readonly array $enabled) {}

    public function isEnabled(string $plugin): bool
    {
        return in_array($plugin, $this->enabled, true);
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}

final class PluginEntityPersistenceStore implements EntityPersistenceStore
{
    /** @var array<string, EntityChunkSnapshot> */
    private array $snapshots = [];

    public function loadEntityChunk(ChunkPosition $position): ?EntityChunkSnapshot
    {
        return $this->snapshots[$position->key()] ?? null;
    }

    public function saveEntityChunk(EntityChunkSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->chunk->key()] = $snapshot;
    }

    public function transferEntityOwnership(EntityOwnershipTransfer $transfer): EntityOwnershipTransferResult
    {
        $this->snapshots[$transfer->sourceAfter->chunk->key()] = $transfer->sourceAfter;
        $this->snapshots[$transfer->destinationAfter->chunk->key()] = $transfer->destinationAfter;

        return new EntityOwnershipTransferResult($transfer->sourceAfter, $transfer->destinationAfter);
    }
}
