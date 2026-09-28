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

namespace Bedriox\Server\Tests\Player;

use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    public function testSnapshotSeparatesMutableWorldStateFromConsumers(): void
    {
        $player = new Player(
            'session',
            17,
            new PlayerIdentity('identity', 'Player'),
            new Position(0.0, 64.0, 0.0),
            4,
            10,
            64.0,
        );
        $before = $player->snapshot();
        $player->movement->position = new Position(2.0, 64.0, 3.0);
        $player->movement->mode = MovementMode::WALKING;
        $player->movement->headYaw = 35.0;
        $player->movement->sneaking = true;
        $after = $player->snapshot();

        self::assertSame(17, $before->runtimeActorId);
        self::assertSame(0.0, $before->position->x);
        self::assertSame(MovementMode::STOPPED, $before->movementMode);
        self::assertSame(2.0, $after->position->x);
        self::assertSame(MovementMode::WALKING, $after->movementMode);
        self::assertSame(35.0, $after->headYaw);
        self::assertTrue($after->sneaking);
        self::assertFalse($after->sprinting);
    }

    public function testIdentityAddsXuidWithoutBreakingExistingConstruction(): void
    {
        $legacy = new PlayerIdentity('identity', 'Player');
        $authenticated = new PlayerIdentity('identity', 'Player', '123456789');

        self::assertSame('', $legacy->xuid);
        self::assertSame('123456789', $authenticated->xuid);
    }

    public function testDirtyRevisionAcknowledgementDoesNotHideNewerState(): void
    {
        $player = new Player(
            'session',
            17,
            new PlayerIdentity('identity', 'Player'),
            new Position(0.0, 64.0, 0.0),
            4,
            10,
            64.0,
        );

        self::assertFalse($player->isDirty());
        self::assertSame(1, $player->markDirty());
        self::assertSame(2, $player->markDirty());
        self::assertTrue($player->acknowledgeSaved(1));
        self::assertTrue($player->isDirty());
        self::assertSame(1, $player->savedRevision());
        self::assertTrue($player->acknowledgeSaved(2));
        self::assertFalse($player->isDirty());
        self::assertFalse($player->acknowledgeSaved(1));
    }

    public function testChangingWorldUsesTheAuthoritativeMutationBoundary(): void
    {
        $player = new Player(
            'session',
            17,
            new PlayerIdentity('identity', 'Player'),
            new Position(0.0, 64.0, 0.0),
            4,
            10,
            64.0,
            worldName: 'world',
        );

        self::assertSame('world', $player->worldName());
        self::assertSame('world', $player->changeWorld('mines'));
        self::assertSame('mines', $player->worldName());
        self::assertSame(1, $player->stateRevision());
        self::assertSame('mines', $player->changeWorld('mines'));
        self::assertSame(1, $player->stateRevision());

        $this->expectException(\InvalidArgumentException::class);
        $player->changeWorld('../outside');
    }

    public function testBootstrapCarriesExactReturningPlayerState(): void
    {
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity', 'Player', '123'),
            'world',
            new Position(-20.5, 80.25, 11.75),
            180.0,
            -35.0,
            new PlayerInventoryState([], 4),
            100,
            200,
        );

        self::assertSame(-20.5, $bootstrap->position->x);
        self::assertSame(180.0, $bootstrap->yaw);
        self::assertSame(4, $bootstrap->inventory->selectedHotbarSlot);
        self::assertSame('survival', $bootstrap->gamemode);
    }
}
