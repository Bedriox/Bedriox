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

namespace Bedriox\Server\Tests\Gameplay\Processing;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Api\Processing\CartographyOperation;
use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Server\Gameplay\Processing\AnvilProcessor;
use Bedriox\Server\Gameplay\Processing\CartographyProcessor;
use Bedriox\Server\Gameplay\Processing\CauldronProcessor;
use Bedriox\Server\Gameplay\Processing\CauldronState;
use Bedriox\Server\Gameplay\Processing\ComposterProcessor;
use Bedriox\Server\Gameplay\Processing\ComposterState;
use Bedriox\Server\Gameplay\Processing\EnchantingProcessor;
use Bedriox\Server\Gameplay\Processing\GrindstoneProcessor;
use Bedriox\Server\Gameplay\Processing\LoomProcessor;
use Bedriox\Server\Gameplay\Processing\WorkstationEvaluationContext;
use Bedriox\Server\Gameplay\Processing\WorkstationItemData;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TransientWorkstationProcessorTest extends TestCase
{
    public function testAnvilCombinesDamageAndEnchantmentsAtomically(): void
    {
        $firstNbt = WorkstationItemData::withEnchantments(null, ['minecraft:unbreaking' => 1]);
        $secondNbt = WorkstationItemData::withEnchantments(null, ['minecraft:unbreaking' => 1]);

        $result = (new AnvilProcessor())->process(
            new ContainerItemStack('minecraft:iron_pickaxe', 1, 200, $firstNbt),
            new ContainerItemStack('minecraft:iron_pickaxe', 1, 150, $secondNbt),
            'Miner',
            250,
        );

        self::assertNotNull($result);
        self::assertSame([0 => 1, 1 => 1], $result->consumedBySlot);
        self::assertLessThan(200, $result->outputs[0]->damage);
        self::assertSame(2, WorkstationItemData::enchantments($result->outputs[0]->nbt)['minecraft:unbreaking']);
    }

    public function testGrindstoneRemovesEnchantmentsAndReturnsExperience(): void
    {
        $nbt = WorkstationItemData::withEnchantments(null, ['minecraft:efficiency' => 4]);
        $result = (new GrindstoneProcessor())->process(
            new ContainerItemStack('minecraft:iron_pickaxe', 1, 20, $nbt),
            null,
            250,
        );

        self::assertNotNull($result);
        self::assertSame([], WorkstationItemData::enchantments($result->outputs[0]->nbt));
        self::assertSame(4, $result->experience);
    }

    public function testAnvilConsumesOnlyMaterialsNeededForRepair(): void
    {
        $result = (new AnvilProcessor())->process(
            new ContainerItemStack('minecraft:iron_pickaxe', 1, 125),
            new ContainerItemStack('minecraft:iron_ingot', 8),
            null,
            250,
        );

        self::assertNotNull($result);
        self::assertSame(3, $result->consumedBySlot[1]);
        self::assertSame(0, $result->outputs[0]->damage);
    }

    public function testGrindstoneRetainsCurses(): void
    {
        $nbt = WorkstationItemData::withEnchantments(null, [
            'minecraft:binding' => 1,
            'minecraft:protection' => 2,
        ]);
        $result = (new GrindstoneProcessor())->process(
            new ContainerItemStack('minecraft:iron_helmet', 1, 0, $nbt),
            null,
            165,
        );

        self::assertNotNull($result);
        self::assertSame(['minecraft:binding' => 1], WorkstationItemData::enchantments($result->outputs[0]->nbt));
    }

    public function testEnchantingOffersAreDeterministicAndCostsAreAuthoritative(): void
    {
        $processor = new EnchantingProcessor();
        $item = new ContainerItemStack('minecraft:iron_sword', 1);
        $first = $processor->options($item, 15, 12345);
        $second = $processor->options($item, 15, 12345);

        self::assertEquals($first, $second);
        self::assertCount(3, $first);
        $result = $processor->apply($item, $first[2], 30, 3);
        self::assertNotNull($result);
        self::assertSame(3, $result->lapisCost);
        self::assertNotSame([], WorkstationItemData::enchantments($result->item->nbt));
    }

    public function testEnchantmentsUseTheBedrockItemNbtContract(): void
    {
        $nbt = WorkstationItemData::withEnchantments(null, [
            'minecraft:sharpness' => 3,
            'minecraft:unbreaking' => 2,
        ]);
        $ench = $nbt->tag('ench');

        self::assertNotNull($ench);
        self::assertSame(TagType::LIST, $ench->type());
        $entries = $ench->value();
        self::assertIsArray($entries);
        self::assertCount(2, $entries);
        self::assertContainsOnlyInstancesOf(Tag::class, $entries);

        self::assertInstanceOf(Tag::class, $entries[0]);
        self::assertInstanceOf(Tag::class, $entries[1]);
        $first = $entries[0]->value();
        $second = $entries[1]->value();
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertInstanceOf(Tag::class, $first['id']);
        self::assertInstanceOf(Tag::class, $first['lvl']);
        self::assertInstanceOf(Tag::class, $second['id']);
        self::assertInstanceOf(Tag::class, $second['lvl']);
        self::assertSame(TagType::SHORT, $first['id']->type());
        self::assertSame(TagType::SHORT, $first['lvl']->type());
        self::assertSame(9, $first['id']->value());
        self::assertSame(3, $first['lvl']->value());
        self::assertSame(17, $second['id']->value());
        self::assertSame(2, $second['lvl']->value());
        self::assertSame(
            ['minecraft:sharpness' => 3, 'minecraft:unbreaking' => 2],
            WorkstationItemData::enchantments($nbt),
        );
    }

    public function testAlreadyEnchantedItemsDoNotReceiveAnotherTableOffer(): void
    {
        $item = new ContainerItemStack(
            'minecraft:iron_sword',
            1,
            nbt: WorkstationItemData::withEnchantments(null, ['minecraft:sharpness' => 1]),
        );

        self::assertSame([], (new EnchantingProcessor())->options($item, 15, 12345));
    }

    public function testLoomAppendsOneBoundedPattern(): void
    {
        $result = (new LoomProcessor())->process(
            new ContainerItemStack('minecraft:white_banner', 1),
            new ContainerItemStack('minecraft:red_dye', 1),
            'cs',
        );

        self::assertNotNull($result);
        $patterns = $result->outputs[0]->nbt?->tag('Patterns')?->value();
        self::assertIsArray($patterns);
        self::assertCount(1, $patterns);
    }

    public function testCartographyScaleAndLockHaveExplicitInputs(): void
    {
        $map = new ContainerItemStack('minecraft:filled_map', 1, nbt: ItemNbt::empty());
        $scaled = (new CartographyProcessor())->process(
            $map,
            new ContainerItemStack('minecraft:paper', 1),
            CartographyOperation::SCALE,
        );

        self::assertNotNull($scaled);
        self::assertSame(1, $scaled->outputs[0]->nbt?->int('map_scale'));
        self::assertSame([0 => 1, 1 => 1], $scaled->consumedBySlot);
    }

    public function testComposterConsumesInputEvenWhenItsExplicitRollFails(): void
    {
        $processor = new ComposterProcessor();
        $failed = $processor->insert(new ComposterState(2), new ContainerItemStack('minecraft:wheat_seeds', 1), 99);
        $succeeded = $processor->insert(new ComposterState(2), new ContainerItemStack('minecraft:wheat_seeds', 1), 0);

        self::assertNotNull($failed);
        self::assertTrue($failed->consumed);
        self::assertSame(2, $failed->state->level);
        self::assertSame(3, $succeeded?->state->level);
        self::assertSame('minecraft:bone_meal', $processor->extract(new ComposterState(8))?->output?->identifier);
    }

    public function testCauldronBucketAndBottleTransitionsAreBounded(): void
    {
        $processor = new CauldronProcessor();
        $filled = $processor->interact(CauldronState::empty(), new ContainerItemStack('minecraft:water_bucket', 1));
        self::assertNotNull($filled);
        self::assertSame(CauldronContentType::WATER, $filled->state->content);
        self::assertSame(6, $filled->state->level);

        $bottle = $processor->interact($filled->state, new ContainerItemStack('minecraft:glass_bottle', 1));
        self::assertNotNull($bottle);
        self::assertSame(4, $bottle->state->level);
        self::assertSame('minecraft:potion', $bottle->heldItem->identifier);
    }

    public function testLeatherWashRemovesOnlyTheColorTag(): void
    {
        $nbt = ItemNbt::empty()
            ->withTag('customColor', Tag::int(0x123456))
            ->withString('owner', 'kept');
        $result = (new CauldronProcessor())->interact(
            new CauldronState(CauldronContentType::WATER, 6),
            new ContainerItemStack('minecraft:leather_helmet', 1, nbt: $nbt),
        );

        self::assertNotNull($result);
        self::assertNull($result->heldItem->nbt?->tag('customColor'));
        self::assertSame('kept', $result->heldItem->nbt?->string('owner'));
    }

    public function testEvaluationContextRejectsUnboundedPlayerIntent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkstationEvaluationContext(bookshelves: 16);
    }
}
