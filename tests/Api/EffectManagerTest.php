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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Effect\EffectActions;
use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectManager;
use Bedriox\Api\Effect\EffectType;
use LogicException;
use PHPUnit\Framework\TestCase;

final class EffectManagerTest extends TestCase
{
    public function testSnapshotReadsAndBoundActions(): void
    {
        $effect = new EffectInstance(EffectType::SPEED, 200, 1);
        $calls = [];
        $manager = new EffectManager(
            [$effect->type->value => $effect],
            new EffectActions(
                static function (EffectInstance $value, EffectCause $cause) use (&$calls): void {
                    $calls[] = ['add', $value, $cause];
                },
                static function (EffectType $type, EffectCause $cause) use (&$calls): void {
                    $calls[] = ['remove', $type, $cause];
                },
                static function (EffectCause $cause) use (&$calls): void {
                    $calls[] = ['clear', $cause];
                },
            ),
        );

        self::assertTrue($manager->has(EffectType::SPEED));
        self::assertSame($effect, $manager->get(EffectType::SPEED));
        $manager->add($effect, EffectCause::POTION);
        $manager->remove(EffectType::SPEED, EffectCause::MILK);
        $manager->clear();

        self::assertSame([
            ['add', $effect, EffectCause::POTION],
            ['remove', EffectType::SPEED, EffectCause::MILK],
            ['clear', EffectCause::PLUGIN],
        ], $calls);
    }

    public function testDetachedSnapshotRejectsMutation(): void
    {
        $manager = new EffectManager([], EffectActions::unavailable());

        $this->expectException(LogicException::class);
        $manager->add(new EffectInstance(EffectType::SPEED, 20));
    }
}
