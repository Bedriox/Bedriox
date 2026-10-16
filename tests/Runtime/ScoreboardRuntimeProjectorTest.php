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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\RemoveObjectivePacket;
use Bedriox\Protocol\Packet\ScoreboardEntryType;
use Bedriox\Protocol\Packet\SetDisplayObjectivePacket;
use Bedriox\Protocol\Packet\SetScoreboardIdentityPacket;
use Bedriox\Protocol\Packet\SetScorePacket;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\Scoreboard\ScoreboardRegistry;
use Bedriox\Server\Runtime\Scoreboard\ScoreboardRuntimeProjector;
use PHPUnit\Framework\TestCase;

final class ScoreboardRuntimeProjectorTest extends TestCase
{
    public function testOneHundredIdenticalViewersRemainOneProjectionBatch(): void
    {
        $players = [];
        for ($index = 1; $index <= 100; ++$index) {
            $players[] = self::player(sprintf('00000000-0000-0000-0000-%012d', $index));
        }
        $registry = self::registry($players);
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        $sidebar->replaceLines([
            'tps' => 'TPS: 20.00',
            'memory' => 'Memory: 256 MB',
            'entities' => 'Entities: 100',
            'players' => 'Players: 100',
            'item' => 'Item: Air',
        ]);
        $sidebar->showToAll();
        $projector = new ScoreboardRuntimeProjector(static fn(string $uuid): int => 1);
        foreach ($players as $player) {
            $projector->requestReplay($player->uuid);
        }

        $batches = $projector->flush($registry);

        self::assertCount(1, $batches);
        self::assertCount(100, $batches[0]->viewerUuids);
        self::assertCount(2, $batches[0]->packets);
        $scorePacket = $batches[0]->packets[1];
        self::assertInstanceOf(SetScorePacket::class, $scorePacket);
        self::assertCount(5, $scorePacket->entries);
    }

    public function testIdenticalViewsShareOnePreparedProjectionAndLineUpdatesAreDeltaOnly(): void
    {
        $first = self::player('00000000-0000-0000-0000-000000000001');
        $second = self::player('00000000-0000-0000-0000-000000000002');
        $registry = self::registry([$first, $second]);
        $sidebar = $registry->forOwner('Example')->createSidebar('Bedriox', 'status');
        $sidebar->setLine('tps', 'TPS: 19');
        $sidebar->showToAll();
        $projector = new ScoreboardRuntimeProjector(static fn(string $uuid): ?int => match ($uuid) {
            strtolower($first->uuid) => 101,
            strtolower($second->uuid) => 102,
            default => null,
        });
        $projector->requestReplay($first->uuid);
        $projector->requestReplay($second->uuid);

        $initial = $projector->flush($registry);
        self::assertCount(1, $initial);
        self::assertSame([$first->uuid, $second->uuid], $initial[0]->viewerUuids);
        self::assertInstanceOf(SetDisplayObjectivePacket::class, $initial[0]->packets[0]);
        self::assertInstanceOf(SetScorePacket::class, $initial[0]->packets[1]);
        $initialScore = $initial[0]->packets[1]->entries[0];

        $sidebar->setLine('tps', 'TPS: 19.5');
        $sidebar->setLine('tps', 'TPS: 20.0');
        $delta = $projector->flush($registry);
        self::assertCount(1, $delta);
        self::assertCount(2, $delta[0]->packets);
        self::assertInstanceOf(SetScorePacket::class, $delta[0]->packets[0]);
        self::assertSame(ScoreboardEntryType::REMOVE, $delta[0]->packets[0]->entries[0]->type);
        self::assertSame($initialScore->scoreboardId, $delta[0]->packets[0]->entries[0]->scoreboardId);
        self::assertInstanceOf(SetScorePacket::class, $delta[0]->packets[1]);
        self::assertSame($initialScore->scoreboardId, $delta[0]->packets[1]->entries[0]->scoreboardId);
        self::assertSame('TPS: 20.0', $delta[0]->packets[1]->entries[0]->name);
    }

    public function testSlotReplacementHideAndReplayAreAuthoritative(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $registry = self::registry([$player]);
        $manager = $registry->forOwner('Example');
        $first = $manager->createSidebar('First', 'first');
        $second = $manager->createSidebar('Second', 'second');
        $first->showTo($player);
        $projector = new ScoreboardRuntimeProjector(static fn(string $uuid): int => 101);
        $projector->requestReplay($player->uuid);
        self::assertCount(1, $projector->flush($registry));

        $second->showTo($player);
        $replacement = $projector->flush($registry);
        self::assertCount(1, $replacement);
        self::assertInstanceOf(RemoveObjectivePacket::class, $replacement[0]->packets[0]);
        self::assertInstanceOf(SetDisplayObjectivePacket::class, $replacement[0]->packets[1]);

        $projector->requestReplay($player->uuid);
        $replay = $projector->flush($registry);
        self::assertInstanceOf(RemoveObjectivePacket::class, $replay[0]->packets[0]);
        self::assertInstanceOf(SetDisplayObjectivePacket::class, $replay[0]->packets[1]);

        $second->hideFrom($player);
        $hidden = $projector->flush($registry);
        self::assertCount(1, $hidden);
        self::assertCount(1, $hidden[0]->packets);
        self::assertInstanceOf(RemoveObjectivePacket::class, $hidden[0]->packets[0]);
    }

    public function testNumericPlayerScoresRegisterIdentityBeforeTheirScore(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $registry = self::registry([$player]);
        $objective = $registry->forOwner('Example')->createObjective('kills', 'Kills');
        $objective->setScore($player, 7);
        $objective->showTo($player, ScoreboardDisplaySlot::BELOW_NAME);
        $projector = new ScoreboardRuntimeProjector(static fn(string $uuid): int => 765);
        $projector->requestReplay($player->uuid);

        $batches = $projector->flush($registry);
        self::assertCount(1, $batches);
        self::assertInstanceOf(SetDisplayObjectivePacket::class, $batches[0]->packets[0]);
        self::assertInstanceOf(SetScoreboardIdentityPacket::class, $batches[0]->packets[1]);
        self::assertSame(765, $batches[0]->packets[1]->entries[0]->playerUniqueId);
        self::assertInstanceOf(SetScorePacket::class, $batches[0]->packets[2]);
        self::assertSame(ScoreboardEntryType::PLAYER, $batches[0]->packets[2]->entries[0]->type);
    }

    public function testDisconnectCleanupRemovesViewsAndDisplayOwnership(): void
    {
        $player = self::player('00000000-0000-0000-0000-000000000001');
        $online = [$player];
        $registry = new ScoreboardRegistry(
            new PluginOwnershipRegistry(),
            static function () use (&$online): array {
                return $online;
            },
        );
        $manager = $registry->forOwner('Example');
        $sidebar = $manager->createSidebar('Bedriox', 'status');
        $objective = $manager->createObjective('kills', 'Kills');
        $view = $sidebar->getViewFor($player);
        $view->setLine('private', 'Private');
        $sidebar->showTo($player);
        $objective->showTo($player, ScoreboardDisplaySlot::PLAYER_LIST);
        $projector = new ScoreboardRuntimeProjector(static fn(string $uuid): int => 765);
        $projector->requestReplay($player->uuid);
        self::assertCount(1, $projector->flush($registry));

        $online = [];
        $registry->removePlayer($player->uuid);
        $projector->forgetPlayer($player->uuid);
        self::assertSame([], $projector->flush($registry));

        self::assertSame([], $sidebar->getViewers());
        self::assertFalse($sidebar->isShowingTo($player));
        self::assertTrue($view->isRemoved());
        self::assertFalse($view->isShowing());
        self::assertFalse($objective->isShowingTo($player, ScoreboardDisplaySlot::PLAYER_LIST));
        $this->expectException(\LogicException::class);
        $sidebar->getViewFor($player);
    }

    public function testQueuedDisconnectCleanupCannotRestoreOfflineProjectionState(): void
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
        $view = $sidebar->getViewFor($player);
        $view->setLine('private', 'Old session');
        $view->show();
        $projector = new ScoreboardRuntimeProjector(static fn(string $uuid): int => 101);
        $projector->requestReplay($player->uuid);
        self::assertCount(1, $projector->flush($registry));

        $online = [];
        $registry->removePlayer($player->uuid);
        $projector->forgetPlayer($player->uuid);
        self::assertSame([], $projector->flush($registry));

        $rejoinedPlayer = self::player($player->uuid);
        $online = [$rejoinedPlayer];
        $replacement = $sidebar->getViewFor($rejoinedPlayer);
        $replacement->show();
        $projector->requestReplay($player->uuid);
        $rejoined = $projector->flush($registry);
        self::assertCount(1, $rejoined);
        self::assertCount(1, $rejoined[0]->packets);
        self::assertInstanceOf(SetDisplayObjectivePacket::class, $rejoined[0]->packets[0]);
        self::assertSame([], $replacement->getLines());
    }

    /** @param list<Player> $players */
    private static function registry(array $players): ScoreboardRegistry
    {
        return new ScoreboardRegistry(new PluginOwnershipRegistry(), static fn(): array => $players);
    }

    private static function player(string $uuid): Player
    {
        return new Player(
            'Player',
            $uuid,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
