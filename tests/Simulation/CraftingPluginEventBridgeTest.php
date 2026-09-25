<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Crafting\CraftingGrid;
use Bedriox\Api\Crafting\RecipeIngredient;
use Bedriox\Api\Crafting\ShapelessRecipe;
use Bedriox\Api\Event\Player\PlayerCraftedItemEvent;
use Bedriox\Api\Event\Player\PlayerCraftItemEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Simulation\Command\CraftingRequest;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;
use Throwable;

final class CraftingPluginEventBridgeTest extends TestCase
{
    public function testBridgeAppliesPreEventOutputReplacementAndPublishesPostEvent(): void
    {
        $dispatcher = new EventDispatcher(
            new CraftingEventRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
        $replacement = [new ItemStack('minecraft:diamond', 1)];
        $post = null;
        $dispatcher->register('Example', PlayerCraftItemEvent::class, static function (PlayerCraftItemEvent $event) use ($replacement): void {
            $event->setOutputs($replacement);
        });
        $dispatcher->register('Example', PlayerCraftedItemEvent::class, static function (PlayerCraftedItemEvent $event) use (&$post): void {
            $post = $event;
        });
        $bridge = new PluginGameplayEventBridge($dispatcher);
        $recipe = new ShapelessRecipe(
            'example:compressed_diamond',
            [RecipeIngredient::exact('minecraft:coal')],
            [new ItemStack('minecraft:diamond', 1)],
        );
        $input = new ItemStack('minecraft:coal', 1);
        $grid = new CraftingGrid(2, 2, [$input, null, null, null]);
        $player = new Player(
            'one',
            1,
            new PlayerIdentity('identity-one', 'One'),
            new Position(0.0, 64.0, 0.0),
            4,
            0,
            63.0,
        );

        self::assertSame($replacement, $bridge->craft(
            $player,
            $recipe,
            $grid,
            1,
            [$input],
            $recipe->outputs(),
        ));
        $bridge->crafted($player, $recipe, $grid, 1, [$input], $replacement);

        self::assertInstanceOf(PlayerCraftedItemEvent::class, $post);
        self::assertSame($replacement, $post->outputs);
        self::assertSame('example:compressed_diamond', $post->recipe->identifier());
    }

    public function testCancelledCraftLeavesTheAuthoritativeGridAndInventoryUnchanged(): void
    {
        $dispatcher = new EventDispatcher(
            new CraftingEventRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
        $postCount = 0;
        $dispatcher->register('Example', PlayerCraftItemEvent::class, static function (PlayerCraftItemEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', PlayerCraftedItemEvent::class, static function () use (&$postCount): void {
            ++$postCount;
        });

        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $catalog = CraftingCatalog::fromData(
            $data,
            $items,
            $states,
            BedrockInventoryPacketProjector::fromData(
                $data,
                new BlockNetworkTranslator($states, $data->blockStateRegistry()),
                $items,
            ),
        );
        $recipe = null;
        foreach ($catalog->recipes()->all() as $candidate) {
            if (count($candidate->ingredients()) === 1 && count($candidate->outputs()) === 1) {
                $recipe = $candidate;
                break;
            }
        }
        self::assertNotNull($recipe);
        $ingredient = $recipe->ingredients()[0];
        $networkId = $catalog->recipes()->networkId($recipe->identifier());
        self::assertNotNull($networkId);

        $world = new WorldSimulation(
            blockPalette: FixedFlatBlockPalette::fromRegistry($states),
            pluginEvents: new PluginGameplayEventBridge($dispatcher),
            itemCatalog: $items,
            blockStateRegistry: $states,
            craftingCatalog: $catalog,
        );
        $factory = new SimulationCommandFactory();
        self::assertTrue($world->enqueue($factory->join(
            'one',
            'identity-one',
            'One',
            bootstrap: new PlayerBootstrap(
                new PlayerIdentity('identity-one', 'One'),
                'world',
                new Position(0.0, 64.0, 0.0),
                0.0,
                0.0,
                new PlayerInventoryState([
                    new PlayerInventoryEntry(
                        0,
                        new PlayerInventoryStackState($ingredient->identifiers[0], $ingredient->count),
                    ),
                ], 0),
                1,
                1,
            ),
        )));
        $world->tick();
        self::assertTrue($world->enqueue($factory->inventoryStackRequest('one', -1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
                $ingredient->count,
            ),
        ])));
        $moved = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $moved);
        self::assertTrue($moved->success, $moved->reason);
        $input = $moved->craftingInventory[0] ?? null;
        self::assertNotNull($input);

        self::assertTrue($world->enqueue($factory->inventoryStackRequest(
            'one',
            -2,
            [
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Consume,
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                    $ingredient->count,
                ),
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Take,
                    new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -2),
                    new InventorySlotReference(InventoryContainer::Main, 0, 0),
                    $recipe->outputs()[0]->count,
                ),
            ],
            crafting: new CraftingRequest($networkId, 1),
        )));
        $cancelled = $world->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $cancelled);
        self::assertFalse($cancelled->success);
        self::assertSame('plugin_cancelled', $cancelled->reason);
        self::assertNull($cancelled->mainInventory[0]);
        $retainedInput = $cancelled->craftingInventory[0];
        self::assertNotNull($retainedInput);
        self::assertSame($ingredient->identifiers[0], $retainedInput->identifier);
        self::assertSame($ingredient->count, $retainedInput->count);
        self::assertSame(0, $postCount);
    }
}

final class CraftingEventRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'Example';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
