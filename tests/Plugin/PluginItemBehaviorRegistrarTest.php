<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Inventory\ArmorDefinition as ApiArmorDefinition;
use Bedriox\Api\Inventory\ConsumableDefinition as ApiConsumableDefinition;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemBehaviorDefinition;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Plugin\PluginItemBehaviorRegistrar;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PluginItemBehaviorRegistrarTest extends TestCase
{
    public function testPublicConsumableIsMappedAndLifecycleCleanupRestoresBuiltIn(): void
    {
        $registry = ItemBehaviorRegistry::vanilla();
        $builtIn = $registry->behavior('minecraft:beetroot_soup');
        self::assertNotNull($builtIn);
        $ownership = new PluginOwnershipRegistry();
        $registrar = new PluginItemBehaviorRegistrar($registry, $ownership);

        $registrar->register('Meals', 'minecraft:beetroot_soup', new ItemBehaviorDefinition(
            ItemUseKind::CONSUME,
            24,
            new ApiConsumableDefinition(
                9,
                7.5,
                false,
                [new ItemStack('minecraft:bowl', 1)],
            ),
            cooldownTicks: 5,
        ), true);

        $behavior = $registry->behavior('minecraft:beetroot_soup');
        self::assertNotNull($behavior);
        self::assertSame('Meals', $behavior->owner);
        self::assertSame(24, $behavior->useDurationTicks);
        self::assertSame(5, $behavior->cooldownTicks);
        self::assertNotNull($behavior->consumable);
        self::assertSame(9.0, $behavior->consumable->foodRestore);
        self::assertSame(7.5, $behavior->consumable->saturationRestore);
        self::assertFalse($behavior->consumable->requiresHunger);
        self::assertSame('minecraft:bowl', $behavior->consumable->residueIdentifier);
        self::assertSame(1, $ownership->count('Meals'));

        self::assertSame([], $ownership->releaseAll('meals'));
        self::assertSame($builtIn, $registry->behavior('minecraft:beetroot_soup'));
    }

    public function testFailedFirstRegistrationDoesNotLeakLifecycleOwnership(): void
    {
        $registry = ItemBehaviorRegistry::vanilla();
        $ownership = new PluginOwnershipRegistry();
        $registrar = new PluginItemBehaviorRegistrar($registry, $ownership);

        try {
            $registrar->register('Meals', 'minecraft:apple', $this->consumable());
            self::fail('Expected the existing built-in behavior to reject registration without replacement.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $ownership->count('Meals'));
        }
    }

    public function testCrossPluginReplacementIsRejectedWithoutAffectingFirstOwner(): void
    {
        $registry = new ItemBehaviorRegistry();
        $ownership = new PluginOwnershipRegistry();
        $registrar = new PluginItemBehaviorRegistrar($registry, $ownership);
        $registrar->register('Meals', 'example:meal', $this->consumable());

        try {
            $registrar->register('OtherPlugin', 'example:meal', $this->consumable(), true);
            self::fail('Expected cross-plugin replacement to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame('Meals', $registry->behavior('example:meal')?->owner);
            self::assertSame(0, $ownership->count('OtherPlugin'));
        }
    }

    public function testSamePluginMayReplaceItsOwnMappedDefinition(): void
    {
        $registry = new ItemBehaviorRegistry();
        $ownership = new PluginOwnershipRegistry();
        $registrar = new PluginItemBehaviorRegistrar($registry, $ownership);
        $registrar->register('Meals', 'example:meal', $this->consumable());
        $replacement = new ItemBehaviorDefinition(
            ItemUseKind::CONSUME,
            8,
            new ApiConsumableDefinition(7, 3.5),
            cooldownTicks: 2,
        );

        $registrar->register('meals', 'example:meal', $replacement, true);

        $behavior = $registry->behavior('example:meal');
        self::assertNotNull($behavior);
        self::assertSame(8, $behavior->useDurationTicks);
        self::assertSame(2, $behavior->cooldownTicks);
        self::assertSame(7.0, $behavior->consumable?->foodRestore);
        self::assertSame(1, $ownership->count('Meals'));
    }

    public function testEquipmentFieldsAreNeverSilentlyDiscardedByConsumableAdapter(): void
    {
        $registrar = new PluginItemBehaviorRegistrar(
            new ItemBehaviorRegistry(),
            new PluginOwnershipRegistry(),
        );

        $this->expectException(InvalidArgumentException::class);
        $registrar->register('Armor', 'example:helmet', new ItemBehaviorDefinition(
            ItemUseKind::EQUIP,
            armor: new ApiArmorDefinition(EquipmentSlot::HEAD, 2, 128),
        ));
    }

    public function testArmorAndOffhandBehaviorAreAppliedAndRestoredWithPluginOwnership(): void
    {
        $data = BedrockDataSet::bundled();
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $original = $items->type('minecraft:iron_helmet');
        $ownership = new PluginOwnershipRegistry();
        $registrar = new PluginItemBehaviorRegistrar(new ItemBehaviorRegistry(), $ownership, $items);

        $registrar->register('Armor', 'minecraft:iron_helmet', new ItemBehaviorDefinition(
            ItemUseKind::EQUIP,
            armor: new ApiArmorDefinition(EquipmentSlot::HEAD, 4, 400, 0.25),
            allowedInOffhand: true,
        ), true);

        $type = $items->type('minecraft:iron_helmet');
        self::assertNotNull($type->armor);
        self::assertSame(ArmorSlot::Head, $type->armor->slot);
        self::assertSame(4, $type->armor->defensePoints);
        self::assertSame(400, $type->armor->maximumDurability);
        self::assertSame(0.25, $type->armor->knockbackResistance);
        self::assertTrue($type->allowedInOffhand);
        self::assertSame([], $ownership->releaseAll('armor'));
        self::assertSame($original, $items->type('minecraft:iron_helmet'));
    }

    public function testConsumableRetainsBoundedRichResidueStacks(): void
    {
        $registry = new ItemBehaviorRegistry();
        $registrar = new PluginItemBehaviorRegistrar($registry, new PluginOwnershipRegistry());
        $residue = [
            new ItemStack('minecraft:bowl', 2, auxValue: 3),
            new ItemStack('minecraft:stick', 4),
        ];

        $registrar->register('Meals', 'example:meal', new ItemBehaviorDefinition(
            ItemUseKind::CONSUME,
            10,
            new ApiConsumableDefinition(3, 1.5, residue: $residue),
        ));

        self::assertEquals($residue, $registry->behavior('example:meal')?->consumable?->residue);
        self::assertNull($registry->behavior('example:meal')?->consumable?->residueIdentifier);
    }

    public function testInstantUseBehaviorRetainsItsKindAndCooldown(): void
    {
        $registry = new ItemBehaviorRegistry();
        $registrar = new PluginItemBehaviorRegistrar($registry, new PluginOwnershipRegistry());

        $registrar->register('Tools', 'example:wand', new ItemBehaviorDefinition(
            ItemUseKind::INSTANT,
            cooldownTicks: 12,
        ));

        $behavior = $registry->behavior('example:wand');
        self::assertNotNull($behavior);
        self::assertSame(ItemUseKind::INSTANT, $behavior->kind);
        self::assertSame(0, $behavior->useDurationTicks);
        self::assertSame(12, $behavior->cooldownTicks);
        self::assertNull($behavior->consumable);
    }

    private function consumable(): ItemBehaviorDefinition
    {
        return new ItemBehaviorDefinition(
            ItemUseKind::CONSUME,
            32,
            new ApiConsumableDefinition(4, 2.4),
        );
    }
}
