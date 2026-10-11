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

use Bedriox\Api\Entity\Value\DefaultEmote;
use Bedriox\Api\Entity\Value\EmoteId;
use PHPUnit\Framework\TestCase;

final class DefaultEmoteTest extends TestCase
{
    public function testEveryDefaultEmoteProvidesItsValidatedContentId(): void
    {
        self::assertCount(103, DefaultEmote::cases());
        self::assertSame('9a469a61-c83b-4ba9-b507-bdbe64430582', DefaultEmote::SIMPLE_CLAP->value);
        self::assertSame('4c8ae710-df2e-47cd-814d-cc7bf21a3d67', DefaultEmote::WAVE->value);
        self::assertCount(103, array_unique(array_column(DefaultEmote::cases(), 'value')));
        foreach (DefaultEmote::cases() as $emote) {
            self::assertInstanceOf(EmoteId::class, $emote->id());
            self::assertSame($emote->value, $emote->id()->value);
        }
    }
}
