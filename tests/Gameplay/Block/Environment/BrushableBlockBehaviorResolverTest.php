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
use Bedriox\Server\Gameplay\Block\Environment\BrushableBlockBehaviorResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrushableBlockBehaviorResolverTest extends TestCase
{
    private BlockStateRegistry $states;
    private BrushableBlockBehaviorResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new BrushableBlockBehaviorResolver($this->states);
    }

    #[DataProvider('brushableProvider')]
    public function testPreservesTheBrushableMaterialAcrossProgressAndExhaustion(
        string $suspicious,
        string $ordinary,
    ): void {
        $state = CanonicalBlockState::from($suspicious, ['brushed_progress' => 0, 'hanging' => 0]);
        $progressed = $this->states->state($this->resolver->withProgress($state, 2));
        $replacement = $this->states->state($this->resolver->exhaustedReplacement($progressed));

        self::assertSame($suspicious, $progressed->identifier());
        self::assertSame(2, $progressed->properties()['brushed_progress']);
        self::assertSame($ordinary, $replacement->identifier());
    }

    /** @return iterable<string, array{string, string}> */
    public static function brushableProvider(): iterable
    {
        yield 'sand' => ['minecraft:suspicious_sand', 'minecraft:sand'];
        yield 'gravel' => ['minecraft:suspicious_gravel', 'minecraft:gravel'];
    }
}
