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

namespace Bedriox\Server\Tests\Runtime\Internal;

use Bedriox\Server\Runtime\Internal\PackedSessionSet;
use PHPUnit\Framework\TestCase;

final class PackedSessionSetTest extends TestCase
{
    public function testMembershipCrossesByteBoundariesAndCompactsAfterRemoval(): void
    {
        $members = '';
        foreach ([0, 7, 8, 63, 149] as $handle) {
            PackedSessionSet::add($members, $handle);
        }

        self::assertSame([0, 7, 8, 63, 149], PackedSessionSet::handles($members));
        self::assertTrue(PackedSessionSet::contains($members, 149));
        self::assertFalse(PackedSessionSet::contains($members, 148));

        PackedSessionSet::remove($members, 149);
        self::assertLessThanOrEqual(8, strlen($members));
        self::assertSame([0, 7, 8, 63], PackedSessionSet::handles($members));
    }
}
