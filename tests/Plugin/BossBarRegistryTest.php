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

use Bedriox\Api\BossBar\BossBarColor;
use Bedriox\Api\BossBar\BossBarStyle;
use Bedriox\Api\Event\Player\PlayerKickCause;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\BossEventAction;
use Bedriox\Protocol\Packet\BossEventPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Server\Plugin\BossBar\BossBarRegistry;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BossBarRegistryTest extends TestCase
{
    public function testProjectsOnlyChangedStateAndUsesInvisibleBackingActor(): void
    {
        $sent = [];
        $player = self::player($sent);
        $registry = new BossBarRegistry(new PluginOwnershipRegistry(), static fn(): array => [$player]);
        $bar = $registry->forOwner('Example')->create('raid', 'Raid', 0.75, BossBarColor::RED, BossBarStyle::SEGMENTED_10);

        $bar->addViewer($player);
        self::assertCount(2, $sent);
        self::assertInstanceOf(AddActorPacket::class, $sent[0]);
        self::assertSame(0.0, $sent[0]->metadata[1]->value);
        $created = $sent[1];
        self::assertInstanceOf(BossEventPacket::class, $created);
        self::assertSame(BossEventAction::CREATE, $created->action);

        $bar->setTitle('Raid');
        $bar->setProgress(0.75);
        $bar->setColor(BossBarColor::RED);
        $bar->setStyle(BossBarStyle::SEGMENTED_10);
        self::assertCount(2, $sent);

        $bar->setProgress(0.5);
        self::assertCount(3, $sent);
        $updated = $sent[2];
        self::assertInstanceOf(BossEventPacket::class, $updated);
        self::assertSame(BossEventAction::UPDATE_PERCENTAGE, $updated->action);
        self::assertSame(0.5, $updated->healthPercentage);
    }

    public function testVisibilityViewerAndRemovalTeardownAreIdempotent(): void
    {
        $sent = [];
        $player = self::player($sent);
        $ownership = new PluginOwnershipRegistry();
        $registry = new BossBarRegistry($ownership, static fn(): array => [$player]);
        $manager = $registry->forOwner('Example');
        $bar = $manager->create('boss', 'Boss');
        $bar->addViewer($player);
        $bar->addViewer($player);
        self::assertCount(2, $sent);

        $bar->setVisible(false);
        $bar->setVisible(false);
        $hidden = $sent[2];
        self::assertInstanceOf(BossEventPacket::class, $hidden);
        self::assertSame(BossEventAction::REMOVE, $hidden->action);
        self::assertInstanceOf(RemoveActorPacket::class, $sent[3]);

        $bar->setVisible(true);
        self::assertCount(6, $sent);
        self::assertTrue($manager->remove('boss'));
        self::assertFalse($manager->remove('boss'));
        self::assertTrue($bar->isRemoved());
        self::assertCount(8, $sent);

        $this->expectException(LogicException::class);
        $bar->setTitle('Removed');
    }

    public function testManagersAreOwnerScopedAndLifecycleCleanupRemovesBars(): void
    {
        $sent = [];
        $player = self::player($sent);
        $ownership = new PluginOwnershipRegistry();
        $registry = new BossBarRegistry($ownership, static fn(): array => [$player]);
        $first = $registry->forOwner('First');
        $second = $registry->forOwner('Second');
        $bar = $first->create('shared', 'First');
        $second->create('shared', 'Second');
        $bar->addViewer($player);

        self::assertSame($bar, $first->get('shared'));
        self::assertNotSame($bar, $second->get('shared'));
        self::assertCount(1, $first->getAll());
        self::assertSame([], $ownership->releaseAll('First'));
        self::assertNull($first->get('shared'));
        self::assertTrue($bar->isRemoved());
        $removed = $sent[2];
        self::assertInstanceOf(BossEventPacket::class, $removed);
        self::assertSame(BossEventAction::REMOVE, $removed->action);
    }

    public function testRejectsUnboundedInput(): void
    {
        $manager = (new BossBarRegistry(new PluginOwnershipRegistry(), static fn(): array => []))->forOwner('Example');

        foreach ([NAN, -0.01, 1.01] as $progress) {
            try {
                $manager->create('invalid', 'Title', $progress);
                self::fail('Invalid boss-bar progress was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $manager->create('UPPERCASE', 'Title');
    }

    public function testViewerCollectionIsBounded(): void
    {
        $sent = [];
        $registry = new BossBarRegistry(new PluginOwnershipRegistry(), static fn(): array => []);
        $bar = $registry->forOwner('Example')->create('bounded', 'Bounded');
        for ($index = 0; $index < BossBarRegistry::MAXIMUM_VIEWERS_PER_BAR; ++$index) {
            $bar->addViewer(self::player($sent, "player-{$index}"));
        }

        $this->expectException(PluginException::class);
        $bar->addViewer(self::player($sent, 'overflow'));
    }

    /** @param list<Packet> $sent */
    private static function player(
        array &$sent,
        string $uuid = '00000000-0000-0000-0000-000000000001',
    ): Player {
        $connection = new PlayerConnection(
            static fn(): bool => true,
            static function (Packet $packet, bool $immediate) use (&$sent): bool {
                $sent[] = $packet;

                return true;
            },
            static fn(string $reason, ?string $quitMessage, ?string $screenMessage, PlayerKickCause $cause, ?string $actor): bool => false,
            static fn(): bool => false,
        );

        return new Player(
            'Player',
            $uuid,
            new Position(1.0, 64.0, 2.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            playerConnection: $connection,
        );
    }
}
