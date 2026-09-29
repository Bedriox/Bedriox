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

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockFace;
use Bedriox\Api\World\Particle\BlockParticle;
use Bedriox\Api\World\Particle\BlockParticleType;
use Bedriox\Api\World\Particle\ColoredParticle;
use Bedriox\Api\World\Particle\ColoredParticleType;
use Bedriox\Api\World\Particle\DragonEggTeleportParticle;
use Bedriox\Api\World\Particle\ItemBreakParticle;
use Bedriox\Api\World\Particle\MobSpawnParticle;
use Bedriox\Api\World\Particle\ParticleBlockState;
use Bedriox\Api\World\Particle\ParticleColor;
use Bedriox\Api\World\Particle\ParticleType;
use Bedriox\Api\World\Particle\ParticleVariables;
use Bedriox\Api\World\Particle\ScalarParticle;
use Bedriox\Api\World\Particle\ScalarParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Api\World\Particle\StandardParticle;
use Bedriox\Api\World\Particle\StandardParticleType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParticleApiTest extends TestCase
{
    public function testNamedCatalogCoversThePinnedBedrockResourceEffects(): void
    {
        $identifiers = array_map(static fn(ParticleType $type): string => $type->value, ParticleType::cases());
        sort($identifiers, SORT_STRING);

        self::assertCount(116, $identifiers);
        self::assertCount(116, array_unique($identifiers));
        self::assertSame(
            '0139976f4aa9b5a20fcc4df9ca3b66efc3a81acb44b0039d9dbd948707a16306',
            hash('sha256', implode("\n", $identifiers)),
        );
        foreach ($identifiers as $identifier) {
            self::assertMatchesRegularExpression('/^minecraft:[a-z0-9_.]+$/D', $identifier);
        }
    }

    public function testSimpleParticleCarriesTypedIdentityAndDeterministicVariables(): void
    {
        $variables = new ParticleVariables([
            'variable.tint_g' => 0.5,
            'variable.tint_r' => 1,
        ]);
        $particle = new SimpleParticle(ParticleType::SPLASH_SPELL, $variables);

        self::assertSame(ParticleType::SPLASH_SPELL, $particle->type());
        self::assertSame(
            '{"variable.tint_g":0.5,"variable.tint_r":1}',
            $particle->variables()?->toJson(),
        );
    }

    public function testTypedPayloadFamiliesRetainCanonicalDataWithoutWireIds(): void
    {
        $color = new ColoredParticle(ColoredParticleType::DUST, new ParticleColor(12, 34, 56, 78));
        $block = new BlockParticle(
            BlockParticleType::PUNCH,
            new ParticleBlockState('minecraft:oak_log', ['pillar_axis' => 'y']),
            BlockFace::UP,
        );
        $item = new ItemBreakParticle(new ItemStack('minecraft:iron_pickaxe', 1, auxValue: 4));

        self::assertSame(78, $color->color->alpha);
        self::assertSame('minecraft:oak_log', $block->block->identifier());
        self::assertSame(['pillar_axis' => 'y'], $block->block->properties());
        self::assertSame(BlockFace::UP, $block->face);
        self::assertSame('minecraft:iron_pickaxe', $item->item->identifier);
        self::assertGreaterThan(0, $color->estimatedBytes());
        self::assertGreaterThan(0, $block->estimatedBytes());
        self::assertGreaterThan(0, $item->estimatedBytes());
        self::assertGreaterThan(0, (new StandardParticle(StandardParticleType::SONIC_EXPLOSION))->estimatedBytes());
        self::assertGreaterThan(0, (new ScalarParticle(ScalarParticleType::SMOKE, 2))->estimatedBytes());
        self::assertGreaterThan(0, (new DragonEggTeleportParticle(-12, 4, 255))->estimatedBytes());
        self::assertGreaterThan(0, (new MobSpawnParticle(2, 3))->estimatedBytes());
        self::assertCount(88, StandardParticleType::cases());
        self::assertCount(6, ScalarParticleType::cases());
        self::assertCount(5, ColoredParticleType::cases());
    }

    #[DataProvider('invalidTypedPayloads')]
    public function testTypedPayloadsRejectInvalidValues(\Closure $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    /** @return iterable<string, array{\Closure(): object}> */
    public static function invalidTypedPayloads(): iterable
    {
        yield 'color channel' => [static fn(): ParticleColor => new ParticleColor(256, 0, 0)];
        yield 'scalar' => [static fn(): ScalarParticle => new ScalarParticle(ScalarParticleType::HEART, -1)];
        yield 'punch face missing' => [static fn(): BlockParticle => new BlockParticle(
            BlockParticleType::PUNCH,
            new ParticleBlockState('minecraft:stone'),
        )];
        yield 'face on terrain' => [static fn(): BlockParticle => new BlockParticle(
            BlockParticleType::TERRAIN,
            new ParticleBlockState('minecraft:stone'),
            BlockFace::NORTH,
        )];
        yield 'dragon offset' => [static fn(): DragonEggTeleportParticle => new DragonEggTeleportParticle(256, 0, 0)];
        yield 'mob width' => [static fn(): MobSpawnParticle => new MobSpawnParticle(256, 1)];
    }

    /** @param array<string, bool|float|int|string> $variables */
    #[DataProvider('invalidVariables')]
    public function testParticleVariablesRejectMalformedOrUnboundedValues(array $variables): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParticleVariables($variables);
    }

    /** @return iterable<string, array{array<string, bool|float|int|string>}> */
    public static function invalidVariables(): iterable
    {
        yield 'raw name' => [['red' => 1]];
        yield 'uppercase name' => [['variable.Red' => 1]];
        yield 'infinite number' => [['variable.red' => INF]];
        yield 'oversized string' => [['variable.text' => str_repeat('x', 257)]];
        yield 'too many values' => [array_fill_keys(
            array_map(static fn(int $index): string => 'variable.v' . $index, range(0, 32)),
            1,
        )];
    }
}
