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

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\GoatEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\EntityActorEffectChanged;
use Bedriox\Server\Simulation\Event\EntityActorEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class EntityControllerRuntimeIntegrationTest extends TestCase
{
    public function testControllerMutationsProjectAndDespawnAuthoritatively(): void
    {
        $simulation = self::simulation();
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 1.0),
        ));
        self::assertInstanceOf(ZombieEntity::class, $spawn->entity);
        $zombie = $spawn->entity;
        $controller = $zombie->getController();

        $controller->setNameTag('Guard');
        $controller->setNameTagVisible(true);
        $controller->setInvisible(true);
        $controller->setScale(1.25);
        $controller->setAiEnabled(false);
        $controller->equipment()->setItem(EquipmentSlot::HEAD, new ItemStack('minecraft:iron_helmet', 1));
        $controller->damage(4.0, EntityDamageCause::PLUGIN);
        $controller->teleport(new ApiPosition(4.0, 65.0, 4.0));

        $events = $simulation->tick()->events;
        self::assertSame(16.0, $zombie->getHealth());
        self::assertSame('Guard', $zombie->nameTag());
        self::assertFalse($zombie->isAiEnabled());
        self::assertSame(4.0, $zombie->getPosition()->x);
        self::assertNotEmpty(self::events($events, EntityActorMetadataChanged::class));
        self::assertNotEmpty(self::events($events, EntityActorEquipmentChanged::class));
        self::assertNotEmpty(self::events($events, EntityActorDamaged::class));
        self::assertNotEmpty(self::events($events, EntityActorMoved::class));

        $controller->despawn();
        $events = $simulation->tick()->events;
        self::assertFalse($controller->isAvailable());
        self::assertCount(1, self::events($events, EntityActorRemoved::class));
    }

    public function testGoatFallReductionAppliesBeforeAuthoritativeDamageCommit(): void
    {
        $simulation = self::simulation();
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::GOAT,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 1.0),
        ));
        self::assertInstanceOf(GoatEntity::class, $spawn->entity);
        $goat = $spawn->entity;

        $goat->getController()->damage(12.0, EntityDamageCause::FALL);
        $events = $simulation->tick()->events;
        self::assertSame(8.0, $goat->getHealth());
        self::assertCount(1, self::events($events, EntityActorDamaged::class));

        $goat->getController()->damage(3.0, EntityDamageCause::ATTACK);
        $simulation->tick();
        self::assertSame(5.0, $goat->getHealth());
    }

    public function testLivingControllerEffectsUseAuthoritativeTimedAndInstantDamagePaths(): void
    {
        $simulation = self::simulation();
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 1.0),
        ));
        self::assertInstanceOf(CowEntity::class, $spawn->entity);
        $zombie = $spawn->entity;
        $effects = $zombie->getController()->effects();

        $effects->add(new EffectInstance(EffectType::RESISTANCE, 200), EffectCause::PLUGIN);
        $events = $simulation->tick()->events;
        self::assertTrue($zombie->effectState()->has(EffectType::RESISTANCE));
        self::assertCount(1, self::events($events, EntityActorEffectChanged::class));

        $effects->add(new EffectInstance(EffectType::INSTANT_DAMAGE, 1), EffectCause::PLUGIN);
        $events = $simulation->tick()->events;
        self::assertFalse($zombie->effectState()->has(EffectType::INSTANT_DAMAGE));
        self::assertEqualsWithDelta(5.2, $zombie->getHealth(), 0.00001);
        self::assertCount(1, self::events($events, EntityActorDamaged::class));
    }

    public function testDeathLootIsSpawnedAfterDeathWithoutRerolling(): void
    {
        $simulation = self::simulation();
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(1.0, 64.0, 1.0),
        ));
        self::assertInstanceOf(CowEntity::class, $spawn->entity);
        $cow = $spawn->entity;
        $cow->getController()->setOnFire(100);
        $cow->getController()->damage(10.0);

        $events = $simulation->tick()->events;
        $deathIndexes = array_keys(array_filter($events, static fn(object $event): bool => $event instanceof EntityActorDied));
        $dropIndexes = array_keys(array_filter($events, static fn(object $event): bool => $event instanceof ItemEntitySpawned));
        self::assertCount(1, $deathIndexes);
        self::assertNotEmpty($dropIndexes);
        $firstDropIndex = $dropIndexes[0] ?? null;
        self::assertNotNull($firstDropIndex);
        self::assertLessThan($firstDropIndex, $deathIndexes[0]);
        self::assertContains(
            'minecraft:cooked_beef',
            array_map(
                static fn(ItemEntitySpawned $event): string => $event->entity->stack->identifier,
                self::events($events, ItemEntitySpawned::class),
            ),
        );
    }

    public function testNaturalZombieEquipmentIsPreparedOnceBeforeSpawn(): void
    {
        $simulation = self::simulation(new MinimumEntityDropRandom());
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::NATURAL,
            'world',
            new Position(1.0, 64.0, 1.0),
        ));
        self::assertInstanceOf(ZombieEntity::class, $spawn->entity);
        $equipment = $spawn->entity->equipmentState();

        self::assertSame('minecraft:leather_helmet', $equipment->getItem(EquipmentSlot::HEAD)?->identifier);
        self::assertSame('minecraft:leather_chestplate', $equipment->getItem(EquipmentSlot::CHEST)?->identifier);
        self::assertSame('minecraft:leather_leggings', $equipment->getItem(EquipmentSlot::LEGS)?->identifier);
        self::assertSame('minecraft:leather_boots', $equipment->getItem(EquipmentSlot::FEET)?->identifier);
        self::assertSame('minecraft:iron_sword', $equipment->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame(0.085, $equipment->getDropChance(EquipmentSlot::MAIN_HAND));
    }

    public function testHeldWeaponDamageAndEntityArmorAreServerAuthoritative(): void
    {
        $simulation = self::simulation();
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ));
        self::assertInstanceOf(ZombieEntity::class, $spawn->entity);
        $zombie = $spawn->entity;
        $zombie->getController()->equipment()->setItem(
            EquipmentSlot::HEAD,
            new ItemStack('minecraft:iron_helmet', 1),
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join(
            'attacker-session',
            'attacker-identity',
            'Attacker',
        )));
        $simulation->tick();
        self::assertTrue($simulation->enqueueGiveItem('attacker-identity', 'minecraft:iron_sword', 1));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($factory->attack(
            'attacker-session',
            $zombie->getRuntimeId(),
            0,
        )));

        $simulation->tick();

        self::assertEqualsWithDelta(13.56, $zombie->getHealth(), 0.000_001);
        self::assertSame(1, $zombie->equipmentState()->getItem(EquipmentSlot::HEAD)?->damage);
        self::assertSame(1, $simulation->pluginPlayer('attacker-identity')?->getInventory()->getItem(0)?->damage);
    }

    private static function simulation(?DropRandom $random = null): WorldSimulation
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

        return new WorldSimulation(
            itemCatalog: $items,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            dropRandom: $random,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
            entityDefinitions: EntityDefinitionRegistry::fromData($data->entityTypeRegistry()),
        );
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return list<T>
     */
    private static function events(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn(object $event): bool => $event instanceof $type));
    }
}

final class MinimumEntityDropRandom implements DropRandom
{
    private int $calls = 0;

    public function integer(int $minimum, int $maximum): int
    {
        ++$this->calls;

        return $minimum;
    }
}
