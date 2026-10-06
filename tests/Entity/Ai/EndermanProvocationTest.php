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

use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\Ai\Sensor\EndermanProvocationSensor;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\EndermanEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EndermanProvocationTest extends TestCase
{
    public function testDirectUnobstructedGazeAcquiresBoundedAngerTarget(): void
    {
        $playerId = EntityUuid::random();
        $player = self::player($playerId, yaw: 0.0, pitch: -5.31);
        $enderman = self::enderman();
        $memory = new AiMemoryStore();

        (new EndermanProvocationSensor())->sense(
            $enderman,
            $memory,
            new AiTickContext(20, self::world($player)),
        );

        self::assertSame($playerId, $enderman->getAngerTargetUniqueId());
        self::assertSame(600, $enderman->getRemainingAngerTicks());
        self::assertSame($player, $memory->get(VanillaAiMemories::nearestPlayer(), 20));
    }

    /** @return iterable<string, array{float, float, bool, bool}> */
    public static function nonProvokingGaze(): iterable
    {
        yield 'facing away' => [180.0, -5.31, false, true];
        yield 'looking above' => [0.0, -45.0, false, true];
        yield 'protected headwear' => [0.0, -5.31, true, true];
        yield 'occluded gaze' => [0.0, -5.31, false, false];
    }

    #[DataProvider('nonProvokingGaze')]
    public function testProximityWithoutValidGazeNeverProvokes(
        float $yaw,
        float $pitch,
        bool $protected,
        bool $visible,
    ): void {
        $player = self::player(EntityUuid::random(), $yaw, $pitch, $protected);
        $enderman = self::enderman();
        $memory = new AiMemoryStore();

        (new EndermanProvocationSensor())->sense(
            $enderman,
            $memory,
            new AiTickContext(20, self::world($player, $visible)),
        );

        self::assertNull($enderman->getAngerTargetUniqueId());
        self::assertSame(0, $enderman->getRemainingAngerTicks());
        self::assertNull($memory->get(VanillaAiMemories::nearestPlayer(), 20));
    }

    public function testDamageProvocationRetainsThenExpiresOnlyThatPlayer(): void
    {
        $playerId = EntityUuid::random();
        $player = self::player($playerId, 180.0, 0.0);
        $enderman = self::enderman();
        $memory = new AiMemoryStore();
        $enderman->setAngerTargetUniqueId($playerId, 40);

        (new EndermanProvocationSensor())->sense(
            $enderman,
            $memory,
            new AiTickContext(10, self::world($player)),
        );
        self::assertSame($player, $memory->get(VanillaAiMemories::nearestPlayer(), 10));

        $enderman->advanceAngerState(20);
        self::assertSame($playerId, $enderman->getAngerTargetUniqueId());
        $enderman->advanceAngerState(20);
        self::assertNull($enderman->getAngerTargetUniqueId());
        self::assertSame(0, $enderman->getRemainingAngerTicks());
    }

    public function testGazeProvocationRemainsTargetedAfterPlayerLooksAwayAcrossAiTicks(): void
    {
        $playerId = EntityUuid::random();
        $looking = self::player($playerId, 0.0, -5.31);
        $lookingAway = self::player($playerId, 180.0, 0.0);
        $enderman = self::enderman();

        for ($tick = 0; $tick <= 4; ++$tick) {
            $enderman->tickAi(new AiTickContext($tick, self::world($looking)), true);
        }
        self::assertSame($playerId, $enderman->getAngerTargetUniqueId());
        self::assertSame(600, $enderman->getRemainingAngerTicks());

        for ($tick = 5; $tick <= 14; ++$tick) {
            $enderman->tickAi(new AiTickContext($tick, self::world($lookingAway)), true);
        }

        self::assertSame($playerId, $enderman->getAngerTargetUniqueId());
        self::assertSame(600, $enderman->getRemainingAngerTicks());
        self::assertSame(
            $lookingAway,
            $enderman->aiRuntime()->memory()->get(VanillaAiMemories::nearestPlayer(), 14),
        );
    }

    private static function player(
        string $playerId,
        float $yaw,
        float $pitch,
        bool $protected = false,
    ): AiPlayerSnapshot {
        return new AiPlayerSnapshot(
            $playerId,
            'end',
            new Position(0.0, 64.0, 0.0),
            headYaw: $yaw,
            pitch: $pitch,
            wearingEndermanProtectiveHeadwear: $protected,
        );
    }

    private static function enderman(): EndermanEntity
    {
        return new EndermanEntity(
            EntityUuid::random(),
            1,
            'end',
            new Position(0.0, 64.0, 10.0),
        );
    }

    private static function world(AiPlayerSnapshot $player, bool $visible = true): IndexedAiWorldView
    {
        return new IndexedAiWorldView(
            new EntityRegistry(),
            static fn(): array => [$player],
            static fn(): bool => $visible,
        );
    }
}
