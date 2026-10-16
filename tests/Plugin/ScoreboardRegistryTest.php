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

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\Scoreboard\ScoreboardObjective;
use Bedriox\Api\Scoreboard\Sidebar;
use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardDirtyResource;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ScoreboardRegistryTest extends TestCase
{
    public function testSidebarLinesUseStableKeysAndOneBasedOrdering(): void
    {
        $registry = self::registry();
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        $sidebar->setLine('tps', 'TPS: 20.00');
        $sidebar->setLine('players', 'Players: 1');
        $sidebar->prependLine('space', '');
        $sidebar->insertBefore('players', 'memory', 'Memory: 128 MB');
        $sidebar->setLine('tps', 'TPS: 19.95');

        self::assertSame([
            'space' => '',
            'tps' => 'TPS: 19.95',
            'memory' => 'Memory: 128 MB',
            'players' => 'Players: 1',
        ], $sidebar->getLines());

        $sidebar->moveLine('players', 1);
        self::assertSame(['players', 'space', 'tps', 'memory'], array_keys($sidebar->getLines()));
        self::assertTrue($sidebar->removeLine('space'));
        self::assertFalse($sidebar->removeLine('missing'));
    }

    public function testViewIsStableAndStoresOnlyPlayerSpecificPresentation(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $registry = self::registry([$player]);
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        $sidebar->replaceLines(['tps' => 'TPS: 20', 'item' => 'Item: Air']);
        $first = $sidebar->getViewFor($player);
        $second = $sidebar->getViewFor(self::player(strtoupper($player->uuid)));

        self::assertSame($first, $second);
        $first->setTitle('Personal Bedriox');
        $first->setLine('item', 'Item: Diamond Sword');
        $first->setLine('coins', 'Coins: 50');
        self::assertSame('Personal Bedriox', $first->getTitle());
        self::assertSame([
            'tps' => 'TPS: 20',
            'item' => 'Item: Diamond Sword',
            'coins' => 'Coins: 50',
        ], $first->getLines());

        $first->removeLine('tps');
        self::assertSame(['item', 'coins'], array_keys($first->getLines()));
        $first->resetLine('tps');
        $first->resetLine('item');
        $first->resetTitle();
        self::assertSame('Bedriox', $first->getTitle());
        self::assertSame(['tps' => 'TPS: 20', 'item' => 'Item: Air', 'coins' => 'Coins: 50'], $first->getLines());
    }

    public function testOnlyOneSidebarShowsToAPlayerAndShowToAllIsIdempotent(): void
    {
        $players = [
            self::player('00000000-0000-0000-0000-000000000001'),
            self::player('00000000-0000-0000-0000-000000000002'),
        ];
        $registry = self::registry($players);
        $manager = $registry->forOwner('Example');
        $first = $manager->createSidebar('First', 'first');
        $second = $manager->createSidebar('Second', 'second');
        $registry->drainDirty();

        $first->showToAll();
        $first->showToAll();
        self::assertCount(2, $first->getViewers());
        $second->showTo($players[0]);
        self::assertFalse($first->isShowingTo($players[0]));
        self::assertTrue($first->isShowingTo($players[1]));
        self::assertTrue($second->isShowingTo($players[0]));

        $dirty = $registry->drainDirty();
        self::assertCount(3, $dirty);
        self::assertSame(
            [ScoreboardDirtyResource::SIDEBAR_VIEW, ScoreboardDirtyResource::SIDEBAR_VIEW, ScoreboardDirtyResource::SIDEBAR_VIEW],
            array_column($dirty, 'resource'),
        );
    }

    public function testRepeatedChangesCoalesceAndNoOpChangesStayClean(): void
    {
        $registry = self::registry();
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        self::assertCount(1, $registry->drainDirty());

        $sidebar->setTitle('Bedriox');
        $sidebar->setLine('tps', 'TPS: 19');
        $sidebar->setLine('tps', 'TPS: 20');
        $sidebar->setLine('tps', 'TPS: 20');
        $dirty = $registry->drainDirty();
        self::assertCount(1, $dirty);
        self::assertSame(3, $dirty[0]->revision);
        self::assertSame([], $registry->drainDirty());
    }

    public function testObjectiveScoresAreBoundedAndDisplaySlotsAreExclusive(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $registry = self::registry([$player]);
        $manager = $registry->forOwner('Example');
        $kills = $manager->createObjective('kills', 'Kills');
        $coins = $manager->createObjective('coins', 'Coins');

        $kills->setScore($player, 5);
        self::assertSame(7, $kills->addScore($player, 2));
        self::assertSame(6, $kills->subtractScore($player, 1));
        self::assertSame(6, $kills->getScore($player));
        $kills->showTo($player, ScoreboardDisplaySlot::BELOW_NAME);
        $coins->showTo($player, ScoreboardDisplaySlot::BELOW_NAME);
        self::assertFalse($kills->isShowingTo($player, ScoreboardDisplaySlot::BELOW_NAME));
        self::assertTrue($coins->isShowingTo($player, ScoreboardDisplaySlot::BELOW_NAME));

        $this->expectException(InvalidArgumentException::class);
        $kills->setScore($player, ScoreboardObjective::MAXIMUM_SCORE + 1);
    }

    public function testPluginCleanupRemovesOwnedResourcesAndTheirHandles(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $ownership = new PluginOwnershipRegistry();
        $registry = new ScoreboardRegistry($ownership, static fn(): array => [$player]);
        $manager = $registry->forOwner('Example');
        $sidebar = $manager->createSidebar('Bedriox', 'status');
        $objective = $manager->createObjective('kills', 'Kills');
        $sidebar->showTo($player);
        $objective->showTo($player, ScoreboardDisplaySlot::PLAYER_LIST);
        $registry->drainDirty();

        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertTrue($sidebar->isRemoved());
        self::assertTrue($objective->isRemoved());
        self::assertSame([], $sidebar->getViewers());
        self::assertFalse($sidebar->isShowingTo($player));
        self::assertFalse($objective->isShowingTo($player, ScoreboardDisplaySlot::PLAYER_LIST));
        self::assertNull($manager->getSidebar('status'));
        self::assertNull($manager->getObjective('kills'));
        self::assertCount(3, $registry->drainDirty());

        $this->expectException(LogicException::class);
        $sidebar->setTitle('Removed');
    }

    public function testDisconnectedPlayerCannotBeAddedBackThroughAStaleSnapshot(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $online = [$player];
        $registry = new ScoreboardRegistry(
            new PluginOwnershipRegistry(),
            static function () use (&$online): array {
                return $online;
            },
        );
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        $sidebar->showTo($player);
        $online = [];
        $registry->removePlayer($player->uuid);

        self::assertSame([], $sidebar->getViewers());
        $this->expectException(LogicException::class);
        $sidebar->showTo($player);
    }

    public function testObjectiveScoresDoNotRetainPlayerSnapshots(): void
    {
        $registry = self::registry();
        $objective = $registry->forOwner('Example')->createObjective('kills', 'Kills');
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $reference = \WeakReference::create($player);

        $objective->setScore($player, 5);
        unset($player);
        gc_collect_cycles();

        self::assertNull($reference->get());
        self::assertSame(
            5,
            $objective->getScore(self::player('00000000-0000-0000-0000-000000000001')),
        );
    }

    public function testSidebarAndViewerLimitsFailBeforeStateChanges(): void
    {
        $players = [];
        for ($index = 0; $index <= ScoreboardRegistry::MAXIMUM_VIEWS_PER_SIDEBAR; ++$index) {
            $players[] = self::player(sprintf('00000000-0000-0000-0000-%012d', $index));
        }
        $registry = self::registry($players);
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        for ($line = 1; $line <= Sidebar::MAXIMUM_LINES; ++$line) {
            $sidebar->setLine("line-{$line}", "Line {$line}");
        }
        try {
            $sidebar->setLine('overflow', 'Overflow');
            self::fail('An oversized sidebar was accepted.');
        } catch (InvalidArgumentException) {
            self::assertCount(Sidebar::MAXIMUM_LINES, $sidebar->getLines());
        }

        for ($index = 0; $index < ScoreboardRegistry::MAXIMUM_VIEWS_PER_SIDEBAR; ++$index) {
            $sidebar->getViewFor($players[$index]);
        }
        $this->expectException(PluginException::class);
        $sidebar->getViewFor($players[ScoreboardRegistry::MAXIMUM_VIEWS_PER_SIDEBAR]);
    }

    /** @param list<Player> $players */
    private static function registry(array $players = []): ScoreboardRegistry
    {
        return new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => $players);
    }

    private static function player(string $uuid): Player
    {
        return new Player(
            'Player',
            $uuid,
            new Position(1.0, 64.0, 2.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
