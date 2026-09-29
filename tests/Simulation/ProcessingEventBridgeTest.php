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

use Bedriox\Api\Event\Processing\CampfireCookEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumedEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumeEvent;
use Bedriox\Api\Event\Processing\FurnaceSmeltEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Processing\CartographyOperation;
use Bedriox\Api\Processing\CauldronChangeCause;
use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Api\Processing\ComposterChangeCause;
use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Api\Processing\FurnaceFuelCause;
use Bedriox\Api\Processing\FurnaceType;
use Bedriox\Api\Processing\SmithingRecipeType;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ProcessingEventBridgeTest extends TestCase
{
    public function testMutableAndCancelledProcessingEventsReturnAuthoritativeOutcomes(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postBurnTicks = null;
        $dispatcher->register('Example', FurnaceFuelConsumeEvent::class, static function (FurnaceFuelConsumeEvent $event): void {
            $event->setBurnTicks(2_000);
        });
        $dispatcher->register('Example', FurnaceFuelConsumedEvent::class, static function (FurnaceFuelConsumedEvent $event) use (&$postBurnTicks): void {
            $postBurnTicks = $event->burnTicks;
        });
        $dispatcher->register('Example', FurnaceSmeltEvent::class, static function (FurnaceSmeltEvent $event): void {
            $event->setResult(new ItemStack('minecraft:charcoal', 1));
        });
        $dispatcher->register('Example', CampfireCookEvent::class, static function (CampfireCookEvent $event): void {
            $event->cancel();
        });
        $position = new BlockPosition(4, 64, -3);
        $coal = new ItemStack('minecraft:coal', 1);
        $log = new ItemStack('minecraft:oak_log', 1);
        $charcoal = new ItemStack('minecraft:charcoal', 1);

        $fuel = $bridge->furnaceFuel($position, FurnaceType::FURNACE, $coal, FurnaceFuelCause::PROCESSING, 1_600);
        self::assertNotNull($fuel);
        self::assertSame(2_000, $fuel->burnTicks());
        $bridge->furnaceFuelConsumed($position, FurnaceType::FURNACE, $coal, FurnaceFuelCause::PROCESSING, $fuel->burnTicks());
        self::assertSame(2_000, $postBurnTicks);

        $smelt = $bridge->furnaceSmelt($position, FurnaceType::FURNACE, $log, $charcoal);
        self::assertNotNull($smelt);
        self::assertSame('minecraft:charcoal', $smelt->result()->identifier);
        self::assertNull($bridge->campfireCook($position, 0, $log, $charcoal));
    }

    public function testEveryStationFamilyCanDispatchPreAndPostEvents(): void
    {
        [, $bridge] = self::bridge();
        $player = self::player();
        $position = new BlockPosition(0, 64, 0);
        $input = new ItemStack('minecraft:stone', 1);
        $addition = new ItemStack('minecraft:coal', 1);
        $result = new ItemStack('minecraft:diamond', 1);
        $option = new EnchantingOption(0, 1, 1, 42, ['minecraft:sharpness' => 1]);

        self::assertNotNull($bridge->furnaceStartSmelt($position, FurnaceType::BLAST_FURNACE, $input, $result, 100));
        $bridge->furnaceStartedSmelting($position, FurnaceType::BLAST_FURNACE, $input, $result, 100);
        $bridge->furnaceSmelted($position, FurnaceType::BLAST_FURNACE, $input, $result);
        self::assertNotNull($bridge->furnaceExtract($player, $position, FurnaceType::BLAST_FURNACE, $result, 2));
        $bridge->furnaceExtracted($player, $position, FurnaceType::BLAST_FURNACE, $result, 2);
        self::assertNotNull($bridge->campfireCookStart($position, 0, $input, $result, 600));
        $bridge->campfireCookingStarted($position, 0, $input, $result, 600);
        $bridge->campfireCooked($position, 0, $input, $result);
        self::assertNotNull($bridge->stonecutterProcess($player, $position, $input, $result, 'stone_to_diamond'));
        $bridge->stonecutterProcessed($player, $position, $input, $result, 'stone_to_diamond');
        self::assertNotNull($bridge->smithingProcess($player, $position, SmithingRecipeType::TRANSFORM, $input, $input, $addition, $result));
        $bridge->smithingProcessed($player, $position, SmithingRecipeType::TRANSFORM, $input, $input, $addition, $result);
        self::assertNotNull($bridge->anvilProcess($player, $position, $input, $addition, $result, 1));
        $bridge->anvilProcessed($player, $position, $input, $addition, $result, 1);
        self::assertNotNull($bridge->grindstoneProcess($player, $position, $input, $addition, $result, 1));
        $bridge->grindstoneProcessed($player, $position, $input, $addition, $result, 1);
        self::assertNotNull($bridge->enchantingOptions($player, $position, $input, [$option]));
        $bridge->enchantingOptionsGenerated($player, $position, $input, [$option]);
        self::assertNotNull($bridge->playerEnchantItem($player, $position, $input, $result, $option));
        $bridge->playerEnchantedItem($player, $position, $input, $result, $option);
        self::assertNotNull($bridge->loomProcess($player, $position, $input, $addition, null, $result, 'stripe_bottom'));
        $bridge->loomProcessed($player, $position, $input, $addition, null, $result, 'stripe_bottom');
        self::assertNotNull($bridge->cartographyProcess($player, $position, CartographyOperation::CLONE, $input, $addition, $result));
        $bridge->cartographyProcessed($player, $position, CartographyOperation::CLONE, $input, $addition, $result);
        self::assertNotNull($bridge->composterChange($player, $position, 0, 1, ComposterChangeCause::INSERT, $input));
        $bridge->composterChanged($player, $position, 0, 1, ComposterChangeCause::INSERT, $input);
        self::assertNotNull($bridge->cauldronChange($player, $position, CauldronContentType::EMPTY, 0, CauldronContentType::WATER, 1, CauldronChangeCause::BUCKET, $input));
        $bridge->cauldronChanged($player, $position, CauldronContentType::EMPTY, 0, CauldronContentType::WATER, 1, CauldronChangeCause::BUCKET, $input);

        self::addToAssertionCount(16);
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

    private static function player(): Player
    {
        return new Player(
            'One',
            'identity-one',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
