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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\Environment\FarmlandBehaviorResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FarmlandBehaviorResolverTest extends TestCase
{
    private BlockStateRegistry $states;
    private FarmlandBehaviorResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new FarmlandBehaviorResolver($this->states);
    }

    public function testWaterImmediatelyFullyHydratesFarmland(): void
    {
        $result = $this->resolver->resolveRandomTick($this->farmland(2), true, false);

        self::assertNotNull($result);
        self::assertSame(7, $this->states->state($result)->properties()['moisturized_amount']);
        self::assertNull($this->resolver->resolveRandomTick($this->farmland(7), true, false));
    }

    public function testDryFarmlandLosesOneMoistureLevelThenReturnsToDirtWithoutCrop(): void
    {
        $result = $this->resolver->resolveRandomTick($this->farmland(3), false, false);
        self::assertNotNull($result);
        self::assertSame(2, $this->states->state($result)->properties()['moisturized_amount']);

        $dirt = $this->resolver->resolveRandomTick($this->farmland(0), false, false);
        self::assertNotNull($dirt);
        self::assertSame('minecraft:dirt', $this->states->state($dirt)->identifier());
        self::assertNull($this->resolver->resolveRandomTick($this->farmland(0), false, true));
    }

    public function testCoverAndSuccessfulTrampleReturnDirt(): void
    {
        $covered = $this->resolver->resolveCovered($this->farmland(7), true);
        self::assertNotNull($covered);
        self::assertSame('minecraft:dirt', $this->states->state($covered)->identifier());
        self::assertNull($this->resolver->resolveCovered($this->farmland(7), false));

        self::assertNull($this->resolver->resolveTrample($this->farmland(7), 1.0, 0.75));
        $trampled = $this->resolver->resolveTrample($this->farmland(7), 1.0, 0.25);
        self::assertNotNull($trampled);
        self::assertSame('minecraft:dirt', $this->states->state($trampled)->identifier());
    }

    #[DataProvider('cropProvider')]
    public function testRecognizesSupportedFarmlandCrops(string $identifier): void
    {
        self::assertTrue($this->resolver->supportsCrop($identifier));
    }

    /** @return iterable<string, array{string}> */
    public static function cropProvider(): iterable
    {
        foreach ([
            'minecraft:wheat', 'minecraft:carrots', 'minecraft:potatoes',
            'minecraft:beetroot', 'minecraft:torchflower_crop', 'minecraft:pitcher_crop',
        ] as $identifier) {
            yield $identifier => [$identifier];
        }
    }

    public function testRejectsInvalidStateAndRandomInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver->resolveTrample(CanonicalBlockState::from('minecraft:dirt'), 1.0, 0.0);
    }

    #[DataProvider('plantingItemProvider')]
    public function testResolvesPlantingItemsToTheirFirstGrowthStage(string $item, string $crop): void
    {
        $state = $this->resolver->cropForItem($item);

        self::assertNotNull($state);
        self::assertSame($crop, $this->states->state($state)->identifier());
    }

    /** @return iterable<string, array{string, string}> */
    public static function plantingItemProvider(): iterable
    {
        yield 'wheat' => ['minecraft:wheat_seeds', 'minecraft:wheat'];
        yield 'carrots' => ['minecraft:carrot', 'minecraft:carrots'];
        yield 'potatoes' => ['minecraft:potato', 'minecraft:potatoes'];
        yield 'beetroot' => ['minecraft:beetroot_seeds', 'minecraft:beetroot'];
        yield 'torchflower' => ['minecraft:torchflower_seeds', 'minecraft:torchflower_crop'];
        yield 'pitcher' => ['minecraft:pitcher_pod', 'minecraft:pitcher_crop'];
    }

    public function testUnknownItemDoesNotPlantOnFarmland(): void
    {
        self::assertNull($this->resolver->cropForItem('minecraft:stick'));
    }

    private function farmland(int $moisture): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:farmland', ['moisturized_amount' => $moisture]);
    }
}
