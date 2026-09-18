<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Block;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Block\VanillaBlockStates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlockStateRegistryTest extends TestCase
{
    public function testCanonicalStatesReceiveDenseIdsIndependentOfInputOrder(): void
    {
        $grass = VanillaBlockStates::grassBlock();
        $air = VanillaBlockStates::air();
        $registry = new BlockStateRegistry([$grass, $air]);

        self::assertSame(0, $registry->internalId($air)->value);
        self::assertSame(1, $registry->internalId($grass)->value);
        self::assertSame('minecraft:air', $registry->state(new InternalBlockStateId(0))->identifier());
    }

    public function testCurrentFlatPaletteRoundTripsAcrossTheNetworkBoundary(): void
    {
        $network = BedrockDataSet::bundled()->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $translator = new BlockNetworkTranslator($internal, $network);
        $flat = FixedFlatBlockPalette::fromRegistry($internal);

        self::assertSame([
            'air' => 17_025,
            'bedrock' => 17_901,
            'dirt' => 13_456,
            'grass_block' => 14_944,
        ], $flat->toNetworkRuntimeIds($translator));
        foreach ([$flat->air, $flat->bedrock, $flat->dirt, $flat->grassBlock] as $internalId) {
            self::assertSame($internalId->value, $translator->fromNetwork($translator->toNetwork($internalId))->value);
        }
    }

    public function testUnknownStatesAndIdsFailInsteadOfLeakingAcrossBoundaries(): void
    {
        $registry = new BlockStateRegistry([VanillaBlockStates::air()]);
        try {
            $registry->internalId(CanonicalBlockState::from('bedriox:missing'));
            self::fail('Unknown canonical state was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->state(new InternalBlockStateId(1));
    }

    public function testEmptyAndDuplicateRegistriesAreRejected(): void
    {
        foreach ([[], [VanillaBlockStates::air(), VanillaBlockStates::air()]] as $states) {
            try {
                new BlockStateRegistry($states);
                self::fail('Invalid internal block-state registry was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUnknownNetworkRuntimeIdFailsAtTranslationBoundary(): void
    {
        $network = BedrockDataSet::bundled()->blockStateRegistry();
        $translator = new BlockNetworkTranslator(new BlockStateRegistry($network->states()), $network);

        $this->expectException(InvalidArgumentException::class);
        $translator->fromNetwork(PHP_INT_MAX);
    }
}
