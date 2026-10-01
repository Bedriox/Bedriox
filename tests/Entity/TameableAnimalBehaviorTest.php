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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CatEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class TameableAnimalBehaviorTest extends TestCase
{
    public function testTamedCatFollowsItsOwnerAndSittingStopsMovement(): void
    {
        $ownerId = EntityUuid::random();
        $cat = new CatEntity(
            EntityUuid::random(),
            7,
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: $ownerId,
        );
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [new AiPlayerSnapshot(
                $ownerId,
                'world',
                new Position(10.0, 64.0, 0.0),
            )],
        );

        for ($tick = 1; $tick <= 10; ++$tick) {
            $cat->tickAi(new AiTickContext($tick, $view), true);
        }
        self::assertGreaterThan(0.0, $cat->getMotion()->x);

        $cat->setSitting(true);
        $cat->tickAi(new AiTickContext(11, $view), true);
        self::assertSame(0.0, $cat->getMotion()->x);
        self::assertSame(0.0, $cat->getMotion()->z);
    }

    public function testAngryWolfTargetsOnlyItsRememberedPlayer(): void
    {
        $targetId = EntityUuid::random();
        $wolf = new WolfEntity(
            EntityUuid::random(),
            9,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $wolf->setAngerTargetUniqueId($targetId, 400);
        $view = new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [
                new AiPlayerSnapshot(EntityUuid::random(), 'world', new Position(1.0, 64.0, 0.0)),
                new AiPlayerSnapshot($targetId, 'world', new Position(6.0, 64.0, 0.0)),
            ],
        );

        for ($tick = 1; $tick <= 10; ++$tick) {
            $wolf->tickAi(new AiTickContext($tick, $view), true);
        }

        self::assertGreaterThan(0.0, $wolf->getMotion()->x);
        self::assertNull($wolf->aiRuntime()->takeMeleeIntent(10));
    }
}
