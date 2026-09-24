<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Item;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\VanillaArmorDefinitions;
use PHPUnit\Framework\TestCase;

final class VanillaArmorDefinitionsTest extends TestCase
{
    public function testCurrentVanillaArmorHasTypedSlotsAndProperties(): void
    {
        $definitions = VanillaArmorDefinitions::all();

        self::assertCount(29, $definitions);
        self::assertSame(ArmorSlot::Head, $definitions['minecraft:diamond_helmet']->slot);
        self::assertSame(3, $definitions['minecraft:diamond_helmet']->defensePoints);
        self::assertSame(364, $definitions['minecraft:diamond_helmet']->maximumDurability);
        self::assertSame(ArmorSlot::Chest, $definitions['minecraft:copper_chestplate']->slot);
        self::assertSame(ArmorSlot::Legs, $definitions['minecraft:netherite_leggings']->slot);
        self::assertSame(ArmorSlot::Feet, $definitions['minecraft:leather_boots']->slot);
        self::assertNull(VanillaArmorDefinitions::definition('minecraft:apple'));
    }

    public function testVanillaCatalogAdmitsArmorAsSingleStackEquipment(): void
    {
        $data = BedrockDataSet::bundled();
        $catalog = ItemCatalog::vanilla($data->itemNetworkRegistry());
        $helmet = $catalog->type('minecraft:diamond_helmet');

        self::assertSame(1, $helmet->maximumStackSize);
        self::assertSame(ArmorSlot::Head, $helmet->armor?->slot);
    }
}
