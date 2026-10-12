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

namespace Bedriox\Server\Tests\Gameplay\Block\Environment;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\Environment\SupportDependentBlockResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportDependentBlockResolverTest extends TestCase
{
    #[DataProvider('supportProvider')]
    public function testResolvesTheSingleRequiredSupport(
        CanonicalBlockState $state,
        int $x,
        int $y,
        int $z,
    ): void {
        $resolver = new SupportDependentBlockResolver();
        $offset = $resolver->supportOffset($state);

        self::assertNotNull($offset);
        self::assertSame([$x, $y, $z], [$offset->x, $offset->y, $offset->z]);
        self::assertTrue($resolver->breaksWithoutSupport($state, false));
        self::assertFalse($resolver->breaksWithoutSupport($state, true));
    }

    /** @return iterable<string, array{CanonicalBlockState, int, int, int}> */
    public static function supportProvider(): iterable
    {
        yield 'lower door' => [CanonicalBlockState::from('minecraft:wooden_door', [
            'door_hinge_bit' => 0,
            'minecraft:cardinal_direction' => 'south',
            'open_bit' => 0,
            'upper_block_bit' => 0,
        ]), 0, -1, 0];
        yield 'standing sign' => [CanonicalBlockState::from('minecraft:standing_sign', [
            'ground_sign_direction' => 0,
        ]), 0, -1, 0];
        yield 'hanging sign' => [CanonicalBlockState::from('minecraft:oak_hanging_sign', [
            'attached_bit' => 0,
            'facing_direction' => 0,
            'ground_sign_direction' => 0,
            'hanging' => 1,
        ]), 0, 1, 0];
        yield 'north-facing wall sign' => [CanonicalBlockState::from('minecraft:wall_sign', [
            'facing_direction' => 2,
        ]), 0, 0, 1];
        yield 'east-facing button' => [CanonicalBlockState::from('minecraft:stone_button', [
            'button_pressed_bit' => 0,
            'facing_direction' => 5,
        ]), -1, 0, 0];
    }

    public function testOrdinaryBlockHasNoSupportDependency(): void
    {
        self::assertNull((new SupportDependentBlockResolver())->supportOffset(
            CanonicalBlockState::from('minecraft:stone'),
        ));
    }

    public function testWallHangingSignIsNotMistakenForACeilingSupportedSign(): void
    {
        $resolver = new SupportDependentBlockResolver();
        $wallSign = CanonicalBlockState::from('minecraft:oak_hanging_sign', [
            'attached_bit' => 0,
            'facing_direction' => 2,
            'ground_sign_direction' => 0,
            'hanging' => 0,
        ]);

        self::assertNull($resolver->supportOffset($wallSign));
        self::assertFalse($resolver->breaksWithoutSupport($wallSign, false));
    }
}
