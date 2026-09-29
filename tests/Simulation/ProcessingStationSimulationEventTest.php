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

use Bedriox\Api\Event\Processing\CampfireCookedEvent;
use Bedriox\Api\Event\Processing\CampfireCookEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumedEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumeEvent;
use Bedriox\Api\Event\Processing\FurnaceStartSmeltEvent;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Processing\CampfireBlockEntity;
use Bedriox\Server\Gameplay\Processing\CampfireType;
use Bedriox\Server\Gameplay\Processing\FurnaceBlockEntity;
use Bedriox\Server\Gameplay\Processing\FurnaceRecipeCatalog;
use Bedriox\Server\Gameplay\Processing\FurnaceType;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

final class ProcessingStationSimulationEventTest extends TestCase
{
    public function testCancelledFurnaceFuelEventPreservesAuthoritativeState(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', FurnaceFuelConsumeEvent::class, static function (FurnaceFuelConsumeEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', FurnaceFuelConsumedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $world] = self::simulation($bridge);
        $position = new BlockPosition(1, 64, 1);
        $furnace = FurnaceBlockEntity::empty(FurnaceType::Furnace, $position);
        $furnace = $furnace->withState(
            $furnace->inventory
                ->withStack(FurnaceBlockEntity::SLOT_INPUT, new ContainerItemStack('minecraft:raw_iron', 1))
                ->withStack(FurnaceBlockEntity::SLOT_FUEL, new ContainerItemStack('minecraft:coal', 1)),
            0,
            0,
            0,
            0,
        );
        $world->setBlockEntity($furnace);
        (new ReflectionMethod(WorldSimulation::class, 'scheduleFurnace'))->invoke($simulation, $position);

        $simulation->tick();

        $state = $world->blockEntityAt($position);
        self::assertInstanceOf(FurnaceBlockEntity::class, $state);
        self::assertSame('minecraft:coal', $state->inventory->stackAt(FurnaceBlockEntity::SLOT_FUEL)?->identifier);
        self::assertSame(0, $state->burnTime);
        self::assertSame(0, $state->cookTime);
        self::assertSame(0, $postEvents);
    }

    public function testFurnaceListenerOutcomesCommitBeforePostEvents(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postBurnTicks = null;
        $dispatcher->register('Example', FurnaceFuelConsumeEvent::class, static function (FurnaceFuelConsumeEvent $event): void {
            $event->setBurnTicks(20);
        });
        $dispatcher->register('Example', FurnaceStartSmeltEvent::class, static function (FurnaceStartSmeltEvent $event): void {
            $event->setCookTicks(10);
        });
        $dispatcher->register('Example', FurnaceFuelConsumedEvent::class, static function (FurnaceFuelConsumedEvent $event) use (&$postBurnTicks): void {
            $postBurnTicks = $event->burnTicks;
        });
        [$simulation, $world, $registry] = self::simulation($bridge);
        $position = new BlockPosition(1, 64, 1);
        foreach ($registry->states() as $blockState) {
            if ($blockState->identifier() === 'minecraft:furnace') {
                $world->setBlockState($position->x, $position->y, $position->z, $registry->internalId($blockState));
                break;
            }
        }
        $furnace = FurnaceBlockEntity::empty(FurnaceType::Furnace, $position);
        $furnace = $furnace->withState(
            $furnace->inventory
                ->withStack(FurnaceBlockEntity::SLOT_INPUT, new ContainerItemStack('minecraft:raw_iron', 1))
                ->withStack(FurnaceBlockEntity::SLOT_FUEL, new ContainerItemStack('minecraft:coal', 1)),
            0,
            0,
            0,
            0,
        );
        $world->setBlockEntity($furnace);
        (new ReflectionMethod(WorldSimulation::class, 'scheduleFurnace'))->invoke($simulation, $position);

        $simulation->tick();

        $state = $world->blockEntityAt($position);
        self::assertInstanceOf(FurnaceBlockEntity::class, $state);
        self::assertSame(19, $state->burnTime);
        self::assertSame(20, $state->burnDuration);
        self::assertSame(1, $state->cookTime);
        self::assertSame(10, $state->cookDuration);
        self::assertSame(20, $postBurnTicks);
        self::assertSame(
            'minecraft:lit_furnace',
            $registry->state($world->blockStateAt($position->x, $position->y, $position->z))->identifier(),
        );
    }

    public function testCancelledCampfireCookRestoresInputAndSuppressesPostEvent(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', CampfireCookEvent::class, static function (CampfireCookEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', CampfireCookedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $world, $registry] = self::simulation($bridge);
        $position = new BlockPosition(2, 64, 2);
        foreach ($registry->states() as $state) {
            if ($state->identifier() === 'minecraft:campfire' && ($state->properties()['extinguished'] ?? null) === 0) {
                $world->setBlockState($position->x, $position->y, $position->z, $registry->internalId($state));
                break;
            }
        }
        $campfire = CampfireBlockEntity::empty(CampfireType::Campfire, $position);
        $campfire = $campfire->withState(
            $campfire->inventory->withStack(0, new ContainerItemStack('minecraft:beef', 1)),
            [0 => 599],
            [0 => 600],
        );
        $world->setBlockEntity($campfire);
        (new ReflectionMethod(WorldSimulation::class, 'scheduleCampfire'))->invoke($simulation, $position);

        $simulation->tick();

        $state = $world->blockEntityAt($position);
        self::assertInstanceOf(CampfireBlockEntity::class, $state);
        self::assertSame('minecraft:beef', $state->inventory->stackAt(0)?->identifier);
        self::assertSame(599, $state->progressBySlot[0]);
        self::assertSame(0, $postEvents);
    }

    /** @return array{EventDispatcher, PluginGameplayEventBridge} */
    private static function bridge(): array
    {
        $runtime = new class implements PluginRuntimeControl {
            public function isEnabled(string $plugin): bool
            {
                return $plugin === 'Example';
            }

            public function version(string $plugin): string
            {
                return '1.0.0';
            }

            public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
        };
        $dispatcher = new EventDispatcher(
            $runtime,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );

        return [$dispatcher, new PluginGameplayEventBridge($dispatcher)];
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry} */
    private static function simulation(PluginGameplayEventBridge $bridge): array
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('processing-events', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(8),
        );
        $world->retainChunk(new ChunkPosition(0, 0));
        $recipes = new FurnaceRecipeCatalog($data->recipeRegistry());

        return [
            new WorldSimulation(
                blockWorld: $world,
                blockPalette: $palette,
                pluginEvents: $bridge,
                blockStateRegistry: $registry,
                furnaceRecipes: $recipes,
            ),
            $world,
            $registry,
        ];
    }
}
