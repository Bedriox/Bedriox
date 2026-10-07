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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\FoxVariant;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityItemConsumedEvent;
use Bedriox\Api\Event\Entity\EntityItemConsumeEvent;
use Bedriox\Api\Event\Entity\EntityPickedUpItemEvent;
use Bedriox\Api\Event\Entity\EntityPickupItemEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Item\DroppedItemEntity;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\FoxEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\EntityActorEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityTotemConsumedPresented;
use Bedriox\Server\Simulation\Event\ItemEntityPickedUp;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

final class FoxQualificationTest extends TestCase
{
    public function testFoxTransientStateInputsAreStrictlyBounded(): void
    {
        $fox = new FoxEntity(EntityUuid::random(), 899, 'world', new Position(0.5, 64.0, 0.5));
        $target = EntityUuid::random();
        $invalidMutations = [
            'zero pounce' => ['beginPounce', [0]],
            'oversized pounce' => ['beginPounce', [41]],
            'zero faceplant' => ['beginFaceplant', [0]],
            'oversized faceplant' => ['beginFaceplant', [101]],
            'zero hunting advance' => ['advanceHuntingState', [0]],
            'oversized hunting advance' => ['advanceHuntingState', [21]],
            'zero defense duration' => ['defendTrustedPlayerAgainst', [$target, 0]],
            'oversized defense duration' => ['defendTrustedPlayerAgainst', [$target, 1_201]],
            'zero defense advance' => ['advanceTrustedDefense', [0]],
            'oversized defense advance' => ['advanceTrustedDefense', [21]],
        ];

        foreach ($invalidMutations as $description => [$method, $arguments]) {
            try {
                (new ReflectionMethod($fox, $method))->invoke($fox, ...$arguments);
                self::fail("Fox mutation unexpectedly accepted {$description}.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFoxSleepsOnlyDuringSafeDaylightAndWakesAtNight(): void
    {
        [$simulation, $world] = self::worldSimulation();
        $fox = self::spawnFox($simulation, new Position(4.5, 64.0, 4.5));

        self::advance($simulation, 20);
        self::assertTrue($fox->isSleeping());

        $world->setTime(13_000);
        self::advance($simulation, 20);
        self::assertFalse($fox->isSleeping());
    }

    public function testUntrustedPlayerCausesFlightWhileTrustedPlayerDoesNot(): void
    {
        $playerId = EntityUuid::random();
        $commands = new SimulationCommandFactory();

        $untrustedSimulation = new WorldSimulation(spawnAnimals: false, spawnMonsters: false);
        self::assertTrue($untrustedSimulation->enqueue($commands->join('untrusted', $playerId, 'Player')));
        $untrustedSimulation->tick();
        $untrusted = self::spawnFox($untrustedSimulation, new Position(5.5, 64.0, 0.5));
        self::advance($untrustedSimulation, 10);
        self::assertGreaterThan(0.0, $untrusted->getMotion()->x);

        $trustedSimulation = new WorldSimulation(spawnAnimals: false, spawnMonsters: false);
        self::assertTrue($trustedSimulation->enqueue($commands->join('trusted', $playerId, 'Player')));
        $trustedSimulation->tick();
        $trusted = self::spawnFox(
            $trustedSimulation,
            new Position(5.5, 64.0, 0.5),
            primaryTrustedPlayerUniqueId: $playerId,
        );
        self::advance($trustedSimulation, 10);
        self::assertLessThanOrEqual(0.0, $trusted->getMotion()->x);
    }

    public function testTrustedFoxDefendsPlayerAndWeaponControlsBiteDamage(): void
    {
        [$simulation] = self::catalogSimulation();
        $playerId = EntityUuid::random();
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('trusted', $playerId, 'Player')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer($playerId);
        self::assertNotNull($player);

        $fox = self::spawnFox(
            $simulation,
            new Position(0.5, 64.0, 0.5),
            primaryTrustedPlayerUniqueId: $playerId,
        );
        $wolf = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::WOLF,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(WolfEntity::class, $wolf);
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:diamond_sword', 1));

        self::invoke($simulation, 'alertTrustedFoxes', $player, $wolf->getUniqueId());
        self::assertSame($wolf->getUniqueId(), $fox->getTrustedDefenseTargetUniqueId());
        self::setTick($simulation, 5);
        self::invoke($simulation, 'advanceFoxDefenseAndAvoidance');

        self::assertLessThan(8.0, $wolf->getHealth());
        self::assertFalse($fox->isSleeping());
    }

    public function testWildPredatorsDriveFoxAway(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $wolf = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::WOLF,
            SpawnCause::COMMAND,
            'world',
            new Position(4.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(WolfEntity::class, $wolf);

        self::setTick($simulation, 5);
        $fox->setSleeping(true);
        self::invoke($simulation, 'advanceFoxDefenseAndAvoidance');

        self::assertLessThan(0.0, $fox->getMotion()->x);
        self::assertFalse($fox->isSleeping());
    }

    public function testFoxPouncesAtNearbyPreyInsteadOfApplyingRemoteDamage(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $chicken = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CHICKEN,
            SpawnCause::COMMAND,
            'world',
            new Position(4.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(ChickenEntity::class, $chicken);
        $health = $chicken->getHealth();
        $fox->setOnGround(true);

        self::setTick($simulation, 5);
        self::assertSame([], self::invoke($simulation, 'advanceLandAnimalPredation'));

        self::assertTrue($fox->isPouncing());
        self::assertSame(0.52, $fox->getMotion()->y);
        self::assertGreaterThan(0.0, $fox->getMotion()->x);
        self::assertSame($health, $chicken->getHealth());
    }

    public function testFoxPounceCompletesOnlyAfterAConfirmedLanding(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: true, spawnAnimals: false, spawnMonsters: false);
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $fox->beginPounce();
        $fox->setOnGround(false);

        self::invoke($simulation, 'reconcileFoxHuntingTransitions');
        self::assertTrue($fox->isPouncing());
        self::assertTrue($fox->hasAirbornePounce());

        $fox->setOnGround(true);
        self::invoke($simulation, 'reconcileFoxHuntingTransitions');
        self::assertFalse($fox->isPouncing());
        self::assertFalse($fox->isFaceplanted());
    }

    public function testFoxDoesNotCollectOrConsumeItemsDuringExclusiveHuntingActivity(): void
    {
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $simulation = new WorldSimulation(
            itemEntities: $items,
            entityAiEnabled: true,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $food = $items->spawn(new InventoryStack('minecraft:sweet_berries', 1, 1), $fox->internalPosition());
        $fox->beginPounce();
        self::setTickForCollection($simulation, $fox);

        self::assertSame([], self::invoke($simulation, 'collectFoxItems'));
        self::assertNotNull($items->get($food->runtimeEntityId));

        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:sweet_berries', 1));
        self::invoke($simulation, 'consumeFoxHeldFood', $fox);
        self::assertSame('minecraft:sweet_berries', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
    }

    public function testFoxReplacesOnlyWithPreferredItemAndPublishesEquipmentChange(): void
    {
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $simulation = new WorldSimulation(
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('observer', EntityUuid::random(), 'Observer')));
        $simulation->tick();
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:sweet_berries', 1));
        $stone = $items->spawn(new InventoryStack('minecraft:stone', 1, 1), $fox->internalPosition());
        self::setTickForCollection($simulation, $fox);
        self::assertSame([], self::invoke($simulation, 'collectFoxItems'));
        self::assertSame('minecraft:sweet_berries', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertNotNull($items->get($stone->runtimeEntityId));

        $totem = $items->spawn(new InventoryStack('minecraft:totem_of_undying', 2, 1), $fox->internalPosition());
        self::setTickForCollection($simulation, $fox);
        $events = self::invoke($simulation, 'collectFoxItems');
        self::assertIsArray($events);
        self::assertCount(1, array_filter($events, static fn(mixed $event): bool => $event instanceof ItemEntityPickedUp));
        self::assertSame('minecraft:totem_of_undying', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        $totemRemainders = array_values(array_filter(
            $items->all(),
            static fn(DroppedItemEntity $item): bool => $item->stack->identifier === 'minecraft:totem_of_undying',
        ));
        self::assertCount(1, $totemRemainders);
        self::assertSame(1, $totemRemainders[0]->stack->count);
        self::assertNotSame($totem->runtimeEntityId, $totemRemainders[0]->runtimeEntityId);

        $tickEvents = $simulation->tick()->events;
        self::assertNotEmpty(array_filter(
            $tickEvents,
            static fn(object $event): bool => $event instanceof EntityActorEquipmentChanged
                && $event->entity === $fox
                && in_array(EquipmentSlot::MAIN_HAND, $event->changedSlots, true),
        ));
    }

    public function testFoxConsumesFoodWithAuthoritativeHealingAndSideEffects(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $fox->damage(6.0);
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:pufferfish', 1));

        self::invoke($simulation, 'consumeFoxHeldFood', $fox);

        self::assertNull($fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND));
        self::assertSame(6.0, $fox->getHealth());
        self::assertTrue($fox->effectState()->has(EffectType::POISON));
    }

    public function testCancelledFoodConsumptionLeavesHeldItemHealthAndEffectsUntouched(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'FoxQualification',
            EntityItemConsumeEvent::class,
            static function (EntityItemConsumeEvent $event) use (&$preEvents): void {
                ++$preEvents;
                $event->cancel();
            },
        );
        $dispatcher->register(
            'FoxQualification',
            EntityItemConsumedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $fox->damage(6.0);
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:pufferfish', 1));

        self::invoke($simulation, 'consumeFoxHeldFood', $fox);

        self::assertSame(1, $preEvents);
        self::assertSame(0, $postEvents);
        self::assertSame('minecraft:pufferfish', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame(4.0, $fox->getHealth());
        self::assertFalse($fox->effectState()->has(EffectType::POISON));
    }

    public function testCancelledPickupLeavesDroppedAndHeldItemsUntouched(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'FoxQualification',
            EntityPickupItemEvent::class,
            static function (EntityPickupItemEvent $event) use (&$preEvents): void {
                ++$preEvents;
                $event->cancel();
            },
        );
        $dispatcher->register(
            'FoxQualification',
            EntityPickedUpItemEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        $items = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:sweet_berries', 1));
        $totem = $items->spawn(new InventoryStack('minecraft:totem_of_undying', 1, 1), $fox->internalPosition());
        self::setTickForCollection($simulation, $fox);

        self::assertSame([], self::invoke($simulation, 'collectFoxItems'));

        self::assertSame(1, $preEvents);
        self::assertSame(0, $postEvents);
        self::assertSame('minecraft:sweet_berries', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame($totem, $items->get($totem->runtimeEntityId));
    }

    public function testFoxTotemSurvivalIsExactOnceAndPublishesMetadata(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('observer', EntityUuid::random(), 'Observer')));
        $simulation->tick();
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $simulation->tick();
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:totem_of_undying', 1));
        $fox->setOnFire(200);

        self::invoke($simulation, 'damageLivingEntity', $fox, 20.0);
        self::assertTrue($fox->isAlive());
        self::assertSame(1.0, $fox->getHealth());
        self::assertFalse($fox->isOnFire());
        self::assertNull($fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND));
        self::assertTrue($fox->effectState()->has(EffectType::REGENERATION));
        self::assertTrue($fox->effectState()->has(EffectType::ABSORPTION));
        self::assertTrue($fox->effectState()->has(EffectType::FIRE_RESISTANCE));

        $events = $simulation->tick()->events;
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityTotemConsumedPresented,
        ));
        self::invoke($simulation, 'damageLivingEntity', $fox, 20.0);
        self::assertFalse($fox->isAlive());
        self::assertFalse($fox->takeTotemPresentation());
    }

    public function testCancelledTotemConsumptionDoesNotPreventLethalDamage(): void
    {
        $dispatcher = self::dispatcher();
        $preEvents = 0;
        $postEvents = 0;
        $dispatcher->register(
            'FoxQualification',
            EntityItemConsumeEvent::class,
            static function (EntityItemConsumeEvent $event) use (&$preEvents): void {
                if ($event->item->identifier === 'minecraft:totem_of_undying') {
                    ++$preEvents;
                    $event->cancel();
                }
            },
        );
        $dispatcher->register(
            'FoxQualification',
            EntityItemConsumedEvent::class,
            static function () use (&$postEvents): void {
                ++$postEvents;
            },
        );
        $simulation = new WorldSimulation(
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $fox->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:totem_of_undying', 1));

        self::invoke($simulation, 'damageLivingEntity', $fox, 20.0);

        self::assertSame(1, $preEvents);
        self::assertSame(0, $postEvents);
        self::assertFalse($fox->isAlive());
        self::assertSame('minecraft:totem_of_undying', $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame([], $fox->effectState()->snapshot());
        self::assertFalse($fox->takeTotemPresentation());
    }

    public function testFoxPersistenceKeepsStableTraitsButClearsTransientCombatState(): void
    {
        $trusted = EntityUuid::random();
        $fox = new FoxEntity(
            EntityUuid::random(),
            901,
            'world',
            new Position(0.5, 64.0, 0.5),
            baby: true,
            variant: FoxVariant::SNOW,
            primaryTrustedPlayerUniqueId: $trusted,
            sleeping: true,
        );
        $fox->beginPounce();
        $fox->defendTrustedPlayerAgainst(EntityUuid::random());

        $restored = new FoxEntity(EntityUuid::random(), 902, 'world', new Position(0.5, 64.0, 0.5));
        $restored->restorePersistenceState(
            $fox->persistenceVariant(),
            $fox->persistenceSchemaVersion(),
            $fox->persistenceData(),
        );

        self::assertTrue($restored->isBaby());
        self::assertSame(FoxVariant::SNOW, $restored->getVariant());
        self::assertTrue($restored->trustsPlayer($trusted));
        self::assertFalse($restored->isPouncing());
        self::assertFalse($restored->isFaceplanted());
        self::assertNull($restored->getTrustedDefenseTargetUniqueId());
        self::assertFalse($restored->isSleeping(), 'Starting a pounce should persist the resulting awake state.');
    }

    public function testFoxDropsItsAuthoritativelyCollectedHeldItemOnDeath(): void
    {
        $itemEntities = new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        [$simulation] = self::catalogSimulation($itemEntities);
        $fox = self::spawnFox($simulation, new Position(0.5, 64.0, 0.5));
        $itemEntities->spawn(
            new InventoryStack('minecraft:diamond_sword', 1, 1),
            $fox->internalPosition(),
        );
        self::setTickForCollection($simulation, $fox);

        self::invoke($simulation, 'collectFoxItems');
        self::assertSame(
            'minecraft:diamond_sword',
            $fox->equipmentState()->getItem(EquipmentSlot::MAIN_HAND)?->identifier,
        );
        $drops = self::invoke($simulation, 'prepareEntityDeathDrops', $fox, null);
        self::assertIsArray($drops);

        self::assertCount(1, array_filter(
            $drops,
            static fn(mixed $drop): bool => $drop instanceof ItemStack
                && $drop->identifier === 'minecraft:diamond_sword',
        ));
    }

    /** @return array{WorldSimulation, World} */
    private static function worldSimulation(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('world', 12345),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));
        $collisions = BlockCollisionRegistry::forGenerationPalette($states, $generation);

        return [new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            waterState: $generation->state('minecraft:water'),
            lavaState: $generation->state('minecraft:lava'),
            blockStateRegistry: $states,
            blockCollisionRegistry: $collisions,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        ), $world];
    }

    /** @return array{WorldSimulation, ItemCatalog} */
    private static function catalogSimulation(?ItemEntityRegistry $itemEntities = null): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );

        return [new WorldSimulation(
            itemCatalog: $items,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            itemEntities: $itemEntities,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        ), $items];
    }

    private static function spawnFox(
        WorldSimulation $simulation,
        Position $position,
        ?string $primaryTrustedPlayerUniqueId = null,
    ): FoxEntity {
        if ($primaryTrustedPlayerUniqueId === null) {
            $entity = $simulation->spawnEntity(new EntitySpawnRequest(
                VanillaEntityType::FOX,
                SpawnCause::COMMAND,
                'world',
                $position,
            ))->entity;
        } else {
            $entity = $simulation->spawnEntity(new EntitySpawnRequest(
                VanillaEntityType::FOX,
                SpawnCause::COMMAND,
                'world',
                $position,
            ))->entity;
            self::assertInstanceOf(FoxEntity::class, $entity);
            $entity->addTrustedPlayerUniqueId($primaryTrustedPlayerUniqueId);
        }
        self::assertInstanceOf(FoxEntity::class, $entity);

        return $entity;
    }

    private static function advance(WorldSimulation $simulation, int $ticks): void
    {
        for ($tick = 0; $tick < $ticks; ++$tick) {
            $simulation->tick();
        }
    }

    private static function setTick(WorldSimulation $simulation, int $tick): void
    {
        (new ReflectionProperty(WorldSimulation::class, 'tick'))->setValue($simulation, $tick);
    }

    private static function setTickForCollection(WorldSimulation $simulation, FoxEntity $fox): void
    {
        self::setTick($simulation, (10 - ($fox->getRuntimeId() % 10)) % 10);
    }

    private static function invoke(WorldSimulation $simulation, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(WorldSimulation::class, $method))->invoke($simulation, ...$arguments);
    }

    private static function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(
            new FoxQualificationRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
    }
}

final class FoxQualificationRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'FoxQualification';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
