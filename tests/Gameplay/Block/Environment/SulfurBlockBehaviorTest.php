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
use Bedriox\Server\Gameplay\Block\Environment\PotentSulfurBehaviorResolver;
use Bedriox\Server\Gameplay\Block\Environment\PotentSulfurState;
use Bedriox\Server\Gameplay\Block\Environment\PotentSulfurSubstrate;
use Bedriox\Server\Gameplay\Block\Environment\SulfurSpikeBehaviorResolver;
use Bedriox\Server\Gameplay\Block\Environment\SulfurSpikeSupportAction;
use Bedriox\Server\World\Block\BlockStateRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SulfurBlockBehaviorTest extends TestCase
{
    private BlockStateRegistry $states;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
    }

    #[DataProvider('potentSulfurProvider')]
    public function testPotentSulfurTransitionsUseWaterAndSubstrate(
        int $depth,
        PotentSulfurSubstrate $substrate,
        bool $active,
        PotentSulfurState $expected,
    ): void {
        $resolver = new PotentSulfurBehaviorResolver($this->states);
        $current = CanonicalBlockState::from('minecraft:potent_sulfur', ['potent_sulfur_state' => 'dry']);
        $resolved = $this->states->state($resolver->resolve($current, $depth, $substrate, $active));
        self::assertSame($expected->value, $resolved->properties()['potent_sulfur_state']);
    }

    /** @return iterable<string, array{int, PotentSulfurSubstrate, bool, PotentSulfurState}> */
    public static function potentSulfurProvider(): iterable
    {
        yield 'dry without water' => [0, PotentSulfurSubstrate::SOURCE_LAVA, true, PotentSulfurState::DRY];
        yield 'wet water pool' => [4, PotentSulfurSubstrate::NONE, false, PotentSulfurState::WET];
        yield 'magma cooldown' => [2, PotentSulfurSubstrate::MAGMA, false, PotentSulfurState::DORMANT];
        yield 'magma eruption' => [2, PotentSulfurSubstrate::MAGMA, true, PotentSulfurState::ERUPTING];
        yield 'source lava continuous' => [1, PotentSulfurSubstrate::SOURCE_LAVA, false, PotentSulfurState::CONTINUOUS];
    }

    public function testPotentSulfurRejectsWaterBeyondItsVanillaColumnLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PotentSulfurBehaviorResolver($this->states))->resolve(
            CanonicalBlockState::from('minecraft:potent_sulfur', ['potent_sulfur_state' => 'wet']),
            5,
            PotentSulfurSubstrate::NONE,
        );
    }

    public function testSulfurSpikePlacementAndSupportAreAsymmetric(): void
    {
        $resolver = new SulfurSpikeBehaviorResolver($this->states);
        $stalactite = $this->states->state($resolver->place(0));
        $stalagmite = $this->states->state($resolver->place(1));
        self::assertSame(1, $stalactite->properties()['hanging']);
        self::assertSame(0, $stalagmite->properties()['hanging']);
        self::assertSame(SulfurSpikeSupportAction::FALL, $resolver->supportAction($stalactite, false));
        self::assertSame(SulfurSpikeSupportAction::BREAK, $resolver->supportAction($stalagmite, false));
        self::assertSame(SulfurSpikeSupportAction::STABLE, $resolver->supportAction($stalactite, true));
        self::assertTrue(SulfurSpikeBehaviorResolver::breaksFromProjectile('minecraft:trident'));
        self::assertTrue(SulfurSpikeBehaviorResolver::breaksFromProjectile('minecraft:thrown_trident'));
    }
}
