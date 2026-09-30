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

use Bedriox\Api\Potion\PotionType;
use Bedriox\Protocol\Packet\LevelEventPacket;
use Bedriox\Protocol\Packet\LevelSoundEventName;
use Bedriox\Protocol\Packet\LevelSoundEventPacket;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\BrewingCompleted;
use Bedriox\Server\Simulation\Event\PotionSplashImpacted;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BedrockWorldEventPacketEncoder::class)]
final class PotionPresentationTest extends TestCase
{
    public function testPotionImpactProjectsColoredSplashAndGlassSound(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new PotionSplashImpacted(
            new Position(1.0, 65.0, 2.0),
            PotionType::TURTLE_MASTER,
            ['viewer'],
        ), []);

        self::assertCount(2, $packets);
        self::assertInstanceOf(LevelEventPacket::class, $packets[0]->packet);
        self::assertInstanceOf(LevelSoundEventPacket::class, $packets[1]->packet);
        self::assertSame(LevelSoundEventName::GLASS, $packets[1]->packet->sound->value);
    }

    public function testBrewingCompletionProjectsSemanticSoundAtBlockCentre(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new BrewingCompleted(
            new BlockPosition(2, 64, 4),
            ['viewer'],
        ), []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(LevelSoundEventPacket::class, $packets[0]->packet);
        self::assertSame(LevelSoundEventName::POTION_BREWED, $packets[0]->packet->sound->value);
        self::assertSame(2.5, $packets[0]->packet->position->x);
        self::assertSame(64.5, $packets[0]->packet->position->y);
    }
}
