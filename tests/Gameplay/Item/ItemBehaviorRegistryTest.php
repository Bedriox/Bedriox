<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Gameplay\Item;

use Bedriox\Server\Gameplay\Item\ConsumableDefinition;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemUseBehavior;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ItemBehaviorRegistryTest extends TestCase
{
    public function testVanillaOrdinaryFoodsHaveBoundedExplicitBehavior(): void
    {
        $registry = ItemBehaviorRegistry::vanilla();

        $apple = $registry->behavior('minecraft:apple');
        self::assertNotNull($apple);
        self::assertSame(32, $apple->useDurationTicks);
        self::assertInstanceOf(ConsumableDefinition::class, $apple->consumable);
        self::assertSame(4.0, $apple->consumable->foodRestore);
        self::assertSame(2.4, $apple->consumable->saturationRestore);
        self::assertNull($registry->behavior('minecraft:chorus_fruit'));
        foreach (['minecraft:golden_apple', 'minecraft:enchanted_golden_apple'] as $identifier) {
            $goldenApple = $registry->behavior($identifier);
            self::assertNotNull($goldenApple);
            $consumable = $goldenApple->consumable;
            self::assertNotNull($consumable);
            self::assertSame(4.0, $consumable->foodRestore);
            self::assertSame(9.6, $consumable->saturationRestore);
            self::assertFalse($consumable->requiresHunger);
        }
    }

    public function testOwnedDefinitionsCanBeReplacedAndRemovedWithoutGlobalState(): void
    {
        $registry = new ItemBehaviorRegistry();
        $definition = new ItemUseBehavior(
            'example:meal',
            12,
            new ConsumableDefinition(2.0, 1.0),
            owner: 'ExamplePlugin',
        );
        $registry->register($definition);
        self::assertSame($definition, $registry->behavior('example:meal'));

        $replacement = new ItemUseBehavior(
            'example:meal',
            8,
            new ConsumableDefinition(3.0, 2.0),
            owner: 'ExamplePlugin',
        );
        $registry->register($replacement, true);
        self::assertSame($replacement, $registry->behavior('example:meal'));
        self::assertSame(1, $registry->unregisterOwnedBy('ExamplePlugin'));
        self::assertNull($registry->behavior('example:meal'));
    }

    public function testPluginOverrideRestoresBuiltInDefinitionOnCaseInsensitiveCleanup(): void
    {
        $registry = ItemBehaviorRegistry::vanilla();
        $builtIn = $registry->behavior('minecraft:apple');
        self::assertNotNull($builtIn);

        $override = new ItemUseBehavior(
            'minecraft:apple',
            10,
            new ConsumableDefinition(8.0, 4.0),
            owner: 'Meals',
        );
        $registry->registerOwned($override, true);
        self::assertSame($override, $registry->behavior('minecraft:apple'));

        self::assertSame(1, $registry->unregisterOwnedBy('mEaLs'));
        self::assertSame($builtIn, $registry->behavior('minecraft:apple'));
    }

    public function testSamePluginMayReplaceItsDefinitionButAnotherPluginMayNot(): void
    {
        $registry = new ItemBehaviorRegistry();
        $registry->registerOwned(new ItemUseBehavior(
            'example:meal',
            12,
            new ConsumableDefinition(2.0, 1.0),
            owner: 'Meals',
        ));
        $replacement = new ItemUseBehavior(
            'example:meal',
            8,
            new ConsumableDefinition(3.0, 2.0),
            owner: 'meals',
        );
        $registry->registerOwned($replacement, true);
        self::assertSame($replacement, $registry->behavior('example:meal'));

        $this->expectException(InvalidArgumentException::class);
        $registry->registerOwned(new ItemUseBehavior(
            'example:meal',
            4,
            new ConsumableDefinition(4.0, 3.0),
            owner: 'OtherPlugin',
        ), true);
    }

    public function testDefinitionRejectsUnboundedNutritionAndDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ItemUseBehavior('example:meal', 0, new ConsumableDefinition(1.0, 0.0));
    }
}
