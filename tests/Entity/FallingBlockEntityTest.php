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

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\Block\FallingBlockEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class FallingBlockEntityTest extends TestCase
{
    public function testCanonicalStateAndFallDataSurvivePersistenceRoundTrip(): void
    {
        $source = new FallingBlockEntity(
            '00000000-0000-4000-8000-000000000001',
            1,
            VanillaEntityDefinitions::fallingBlock(),
            'world',
            new Position(2.5, 80.0, 3.5),
            CanonicalBlockState::from('minecraft:gravel'),
            true,
            2.0,
            40.0,
        );
        $source->moveTo('world', new Position(2.5, 74.0, 3.5), 0.0, 0.0);

        $restored = new FallingBlockEntity(
            '00000000-0000-4000-8000-000000000002',
            2,
            VanillaEntityDefinitions::fallingBlock(),
            'world',
            new Position(2.5, 74.0, 3.5),
            CanonicalBlockState::from('minecraft:sand'),
        );
        $restored->restorePersistenceState(
            $source->persistenceVariant(),
            $source->persistenceSchemaVersion(),
            $source->persistenceData(),
        );

        self::assertSame('minecraft:gravel', $restored->getBlockIdentifier());
        self::assertSame([], $restored->getBlockProperties());
        self::assertTrue($restored->dropsAsItem());
        self::assertSame(2.0, $restored->damagePerBlock());
        self::assertSame(40.0, $restored->maximumDamage());
        self::assertSame(6.0, $restored->getFallDistance());
    }
}
