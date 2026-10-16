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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\Scoreboard\PlayerScoreboardDisplayEvent;
use Bedriox\Api\Event\Scoreboard\ScoreboardScoreChangeEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardRegistry;
use PHPUnit\Framework\TestCase;

final class ScoreboardEventTest extends TestCase
{
    public function testScoreChangesCanBeAdjustedCancelledAndSkipNoOps(): void
    {
        $player = self::player();
        $events = [];
        $registry = new ScoreboardRegistry(
            new PluginOwnershipRegistry(),
            static fn(): array => [$player],
            static function (Event $event) use (&$events): Event {
                $events[] = $event;
                if ($event instanceof ScoreboardScoreChangeEvent && $event->score() === 10) {
                    $event->setScore(7);
                } elseif ($event instanceof ScoreboardScoreChangeEvent && $event->score() === 11) {
                    $event->cancel();
                }

                return $event;
            },
        );
        $objective = $registry->forOwner('Example')->createObjective('kills', 'Kills');

        $objective->setScore($player, 10);
        self::assertSame(7, $objective->getScore($player));
        $objective->setScore($player, 7);
        $objective->setScore($player, 11);
        self::assertSame(7, $objective->getScore($player));
        self::assertCount(2, $events);
    }

    public function testDisplayCancellationAndCleanupBypassesEvents(): void
    {
        $player = self::player();
        $ownership = new PluginOwnershipRegistry();
        $events = [];
        $cancelNext = true;
        $registry = new ScoreboardRegistry(
            $ownership,
            static fn(): array => [$player],
            static function (Event $event) use (&$events, &$cancelNext): Event {
                if ($event instanceof PlayerScoreboardDisplayEvent) {
                    $events[] = $event;
                    if ($cancelNext) {
                        $event->cancel();
                        $cancelNext = false;
                    }
                }

                return $event;
            },
        );
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');

        $sidebar->showTo($player);
        self::assertFalse($sidebar->isShowingTo($player));
        $sidebar->showTo($player);
        self::assertTrue($sidebar->isShowingTo($player));
        self::assertCount(2, $events);

        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertCount(2, $events, 'Plugin teardown must not dispatch cancellable display events.');
    }

    public function testSidebarAndObjectiveShareTheSidebarDisplaySlot(): void
    {
        $player = self::player();
        $registry = new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => [$player]);
        $manager = $registry->forOwner('Example');
        $sidebar = $manager->createSidebar('Bedriox', 'status');
        $objective = $manager->createObjective('kills', 'Kills');

        $sidebar->showTo($player);
        $objective->showTo($player, ScoreboardDisplaySlot::SIDEBAR);
        self::assertFalse($sidebar->isShowingTo($player));
        self::assertTrue($objective->isShowingTo($player, ScoreboardDisplaySlot::SIDEBAR));
    }

    public function testObjectiveSortOrderChangeDispatchesDisplayEvent(): void
    {
        $player = self::player();
        $events = [];
        $registry = new ScoreboardRegistry(
            new PluginOwnershipRegistry(),
            static fn(): array => [$player],
            static function (Event $event) use (&$events): Event {
                if ($event instanceof PlayerScoreboardDisplayEvent) {
                    $events[] = $event;
                }

                return $event;
            },
        );
        $objective = $registry->forOwner('Example')->createObjective('kills', 'Kills');

        $objective->showTo($player, ScoreboardDisplaySlot::SIDEBAR);
        $objective->showTo(
            $player,
            ScoreboardDisplaySlot::SIDEBAR,
            \Bedriox\Api\Scoreboard\ScoreboardSortOrder::ASCENDING,
        );

        self::assertCount(2, $events);
        self::assertSame($objective, $events[1]->previous);
        self::assertSame($objective, $events[1]->display);
        self::assertSame(\Bedriox\Api\Scoreboard\ScoreboardSortOrder::ASCENDING, $events[1]->sortOrder);
    }

    private static function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
