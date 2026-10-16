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

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class IndexedAiWorldViewTest extends TestCase
{
    public function testPlayerSnapshotIsBuiltOnceUntilExplicitlyRefreshed(): void
    {
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $supplierCalls = 0;
        $players = [new AiPlayerSnapshot('first', 'world', new Position(2.0, 64.0, 0.0))];
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static function () use (&$supplierCalls, &$players): array {
                ++$supplierCalls;

                return $players;
            },
        );

        $nearest = $view->nearestPlayer($zombie, 32.0);
        self::assertNotNull($nearest);
        self::assertSame('first', $nearest->playerId);
        self::assertSame(4.0, $view->nearestPlayerDistanceSquared($zombie));
        self::assertSame('first', $view->playerByIdentity('first')?->playerId);
        self::assertSame(1, $supplierCalls);

        $players = [new AiPlayerSnapshot('second', 'world', new Position(3.0, 64.0, 0.0))];
        self::assertSame('first', $view->nearestPlayer($zombie, 32.0)?->playerId);
        $view->refreshPlayerSnapshot();
        self::assertSame('second', $view->nearestPlayer($zombie, 32.0)?->playerId);
        self::assertNull($view->playerByIdentity('first'));
        self::assertSame(2, $supplierCalls);
    }

    public function testIndexedSearchPreservesWorldDamageAndDeterministicTieRules(): void
    {
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(64.0, 64.0, 64.0),
        );
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [
                new AiPlayerSnapshot('other-world', 'other', new Position(64.0, 64.0, 64.0)),
                new AiPlayerSnapshot('spectator', 'world', new Position(64.5, 64.0, 64.0), false),
                new AiPlayerSnapshot('zulu', 'world', new Position(65.0, 64.0, 64.0)),
                new AiPlayerSnapshot('alpha', 'world', new Position(63.0, 64.0, 64.0)),
                new AiPlayerSnapshot('different-cell', 'world', new Position(127.0, 64.0, 64.0)),
            ],
        );

        self::assertSame('alpha', $view->nearestPlayer($zombie, 64.0)?->playerId);
        self::assertSame(1.0, $view->nearestPlayerDistanceSquared($zombie));
    }

    public function testLineOfSightFallsThroughToNextNearestVisiblePlayer(): void
    {
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $queries = [];
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [
                new AiPlayerSnapshot('far', 'world', new Position(3.0, 64.0, 0.0)),
                new AiPlayerSnapshot('hidden', 'world', new Position(1.0, 64.0, 0.0)),
                new AiPlayerSnapshot('visible', 'world', new Position(2.0, 64.0, 0.0)),
            ],
            static function (AbstractMobEntity $_entity, AiPlayerSnapshot $player) use (&$queries): bool {
                $queries[] = $player->playerId;

                return $player->playerId !== 'hidden';
            },
        );

        self::assertSame('visible', $view->nearestPlayer($zombie, 32.0)?->playerId);
        self::assertSame(['hidden', 'visible'], $queries);
    }

    public function testActivationDistanceDoesNotPerformTheHostileLineOfSightQuery(): void
    {
        $entities = new EntityRegistry();
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $lineOfSightQueries = 0;
        $view = new IndexedAiWorldView(
            $entities,
            static fn(): array => [new AiPlayerSnapshot(
                'player-one',
                'world',
                new Position(1.0, 64.0, 0.0),
            )],
            static function (AbstractMobEntity $_entity, AiPlayerSnapshot $_player) use (&$lineOfSightQueries): bool {
                ++$lineOfSightQueries;

                return false;
            },
        );

        self::assertSame(1.0, $view->nearestPlayerDistanceSquared($zombie));
        self::assertSame(0, $lineOfSightQueries);
        self::assertNull($view->nearestPlayer($zombie, 8.0));
        self::assertSame(1, $lineOfSightQueries);
    }

    public function testTargetSearchRejectsDistantPlayersBeforeLineOfSight(): void
    {
        $zombie = new ZombieEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $lineOfSightQueries = [];
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [
                new AiPlayerSnapshot('far', 'world', new Position(200.0, 64.0, 0.0)),
                new AiPlayerSnapshot('near', 'world', new Position(2.0, 64.0, 0.0)),
                new AiPlayerSnapshot('farther', 'world', new Position(3.0, 64.0, 0.0)),
            ],
            static function (AbstractMobEntity $_entity, AiPlayerSnapshot $player) use (&$lineOfSightQueries): bool {
                $lineOfSightQueries[] = $player->playerId;

                return true;
            },
        );

        self::assertSame('near', $view->nearestPlayer($zombie, 32.0)?->playerId);
        self::assertSame(['near'], $lineOfSightQueries);
    }
}
