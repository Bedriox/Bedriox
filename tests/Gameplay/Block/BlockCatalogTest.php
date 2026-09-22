<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Block;

use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\BlockDropKind;
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\VanillaBlockStates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlockCatalogTest extends TestCase
{
    public function testVanillaCatalogDefinesEveryBuiltInGeneratorBlock(): void
    {
        $catalog = BlockCatalog::vanilla();

        self::assertCount(30, $catalog->all());
        self::assertFalse($catalog->type('minecraft:bedrock')->isBreakable());
        self::assertFalse($catalog->type('minecraft:water')->isBreakable());
        self::assertSame(0.6, $catalog->type('minecraft:grass_block')->hardness);
        self::assertSame(ToolType::Pickaxe, $catalog->type('minecraft:diamond_ore')->preferredTool);
        self::assertSame(ToolTier::Iron, $catalog->type('minecraft:diamond_ore')->requiredTier);
        self::assertSame(BlockDropKind::CobbledDeepslate, $catalog->type('minecraft:deepslate')->dropKind);
        self::assertSame(BlockDropKind::Self, $catalog->type('minecraft:cobblestone')->dropKind);
        self::assertSame(BlockDropKind::Self, $catalog->type('minecraft:cobbled_deepslate')->dropKind);
    }

    public function testCanonicalAndInternalLookupsRemainIndependentOfNetworkRuntimeIds(): void
    {
        $catalog = BlockCatalog::vanilla();
        $states = array_map(static fn($type) => $type->state, $catalog->all());
        $registry = new BlockStateRegistry($states);
        $state = VanillaBlockStates::redstoneOre();

        self::assertSame(
            BlockDropKind::Redstone,
            $catalog->typeForInternalId($registry->internalId($state), $registry)->dropKind,
        );
        self::assertSame('minecraft:redstone_ore', $catalog->typeForState($state)->identifier());
    }

    public function testUnknownBlockFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlockCatalog::vanilla()->type('minecraft:not_supported');
    }
}
