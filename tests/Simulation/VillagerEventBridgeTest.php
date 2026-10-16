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

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerType;
use Bedriox\Api\Event\Entity\Villager\VillagerOffersPreparedEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerOffersPrepareEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerProfessionChangedEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerProfessionChangeEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerRestockedEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerRestockEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerTradedEvent;
use Bedriox\Api\Event\Entity\Villager\VillagerTradeEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;
use Throwable;

final class VillagerEventBridgeTest extends TestCase
{
    public function testProfessionAndOfferPreparationEventsAreAdjustableAndObservable(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $villager = self::villager();
        $prepared = null;
        $professionChanged = null;
        $dispatcher->register('Example', VillagerProfessionChangeEvent::class, static function (VillagerProfessionChangeEvent $event): void {
            $event->setProfession(VillagerProfession::LIBRARIAN);
        });
        $dispatcher->register('Example', VillagerProfessionChangedEvent::class, static function (VillagerProfessionChangedEvent $event) use (&$professionChanged): void {
            $professionChanged = $event->profession;
        });
        $dispatcher->register('Example', VillagerOffersPrepareEvent::class, static function (VillagerOffersPrepareEvent $event): void {
            $event->setOffers(new VillagerTradeList([self::offer('bedriox:paper')], 2));
        });
        $dispatcher->register('Example', VillagerOffersPreparedEvent::class, static function (VillagerOffersPreparedEvent $event) use (&$prepared): void {
            $prepared = $event->offers;
        });

        $profession = $bridge->villagerProfessionChange(
            $villager,
            VillagerProfession::UNEMPLOYED,
            VillagerProfession::FARMER,
        );
        self::assertSame(VillagerProfession::LIBRARIAN, $profession);
        $bridge->villagerProfessionChanged($villager, VillagerProfession::UNEMPLOYED, $profession);
        self::assertSame(VillagerProfession::LIBRARIAN, $professionChanged);

        $offers = $bridge->villagerOffersPrepare($villager, new VillagerTradeList([]));
        self::assertSame(2, $offers->generation);
        self::assertSame('bedriox:paper', $offers->offers[0]->key);
        $bridge->villagerOffersPrepared($villager, $offers);
        self::assertSame($offers, $prepared);
    }

    public function testTradeAndRestockEventsSupportCancellationMutationAndPostNotifications(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $villager = self::villager();
        $player = self::player();
        $traded = null;
        $restocked = null;
        $dispatcher->register('Example', VillagerTradeEvent::class, static function (VillagerTradeEvent $event): void {
            $offer = $event->offer();
            $event->setOffer(new VillagerTradeOffer(
                $offer->key,
                $offer->level,
                $offer->firstInput,
                $offer->secondInput,
                new ItemStack('minecraft:book', 2),
                $offer->uses,
                $offer->maximumUses,
                $offer->villagerExperience,
                $offer->rewardsPlayerExperience,
                $offer->priceMultiplier,
                $offer->demand,
                $offer->specialPrice,
            ));
        });
        $dispatcher->register('Example', VillagerTradedEvent::class, static function (VillagerTradedEvent $event) use (&$traded): void {
            $traded = $event;
        });
        $dispatcher->register('Example', VillagerRestockEvent::class, static function (VillagerRestockEvent $event): void {
            $event->setOffers(new VillagerTradeList([self::offer('bedriox:restocked')], 3));
        });
        $dispatcher->register('Example', VillagerRestockedEvent::class, static function (VillagerRestockedEvent $event) use (&$restocked): void {
            $restocked = $event;
        });

        $offer = self::offer('bedriox:paper');
        $adjusted = $bridge->villagerTrade($player, $villager, $offer);
        self::assertNotNull($adjusted);
        self::assertSame(2, $adjusted->result->count);
        $bridge->villagerTraded($player, $villager, $adjusted, VillagerLevel::APPRENTICE, 11, 3);
        self::assertInstanceOf(VillagerTradedEvent::class, $traded);
        self::assertSame(3, $traded->playerExperience);

        $offers = $bridge->villagerRestock($villager, 7, new VillagerTradeList([$offer], 1));
        self::assertNotNull($offers);
        self::assertSame(3, $offers->generation);
        $bridge->villagerRestocked($villager, 7, $offers);
        self::assertInstanceOf(VillagerRestockedEvent::class, $restocked);
        self::assertSame(7, $restocked->worldDay);
    }

    public function testCancelledTradeAndRestockDoNotProduceAuthoritativeValues(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', VillagerTradeEvent::class, static fn(VillagerTradeEvent $event) => $event->cancel());
        $dispatcher->register('Example', VillagerRestockEvent::class, static fn(VillagerRestockEvent $event) => $event->cancel());
        $villager = self::villager();

        self::assertNull($bridge->villagerTrade(self::player(), $villager, self::offer('bedriox:paper')));
        self::assertNull($bridge->villagerRestock($villager, 1, new VillagerTradeList([])));
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

    private static function villager(): VillagerEntity
    {
        return new VillagerEntity(
            '00000000-0000-4000-8000-000000000091',
            91,
            'world',
            new Position(0.0, 64.0, 0.0),
            profession: VillagerProfession::LIBRARIAN,
            villagerType: VillagerType::PLAINS,
        );
    }

    private static function player(): Player
    {
        return new Player(
            'session-one',
            1,
            new PlayerIdentity('identity-one', 'One'),
            new Position(0.0, 64.0, 0.0),
            4,
            0,
            64.0,
            PlayerInventory::empty(),
        );
    }

    private static function offer(string $key): VillagerTradeOffer
    {
        return new VillagerTradeOffer(
            $key,
            VillagerLevel::NOVICE,
            new ItemStack('minecraft:emerald', 1),
            null,
            new ItemStack('minecraft:paper', 1),
        );
    }
}
