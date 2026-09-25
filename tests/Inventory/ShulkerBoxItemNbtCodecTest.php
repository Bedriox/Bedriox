<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Inventory;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Inventory\ShulkerBoxItemNbtCodec;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShulkerBoxItemNbtCodecTest extends TestCase
{
    public function testPortableItemRoundTripPreservesContentsAndUsesNewPlacementFacing(): void
    {
        $nestedNbt = ItemNbt::empty()->withString('bedriox:test', 'value');
        $inventory = new ContainerInventory(27, [
            2 => new ContainerItemStack('minecraft:diamond', 3),
            26 => new ContainerItemStack('minecraft:iron_pickaxe', 1, 17, $nestedNbt, 4),
        ]);
        $source = new ContainerBlockEntity(
            BlockEntityType::ShulkerBox,
            new BlockPosition(1, 65, 2),
            $inventory,
            'Supplies',
            facing: 2,
        );
        $codec = new ShulkerBoxItemNbtCodec();

        $restored = $codec->decode($codec->encode($source), new BlockPosition(9, 70, -4), 5);

        self::assertSame('Supplies', $restored->customName);
        self::assertSame(5, $restored->facing);
        $diamonds = $restored->inventory->stackAt(2);
        $pickaxe = $restored->inventory->stackAt(26);
        self::assertInstanceOf(ContainerItemStack::class, $diamonds);
        self::assertInstanceOf(ContainerItemStack::class, $pickaxe);
        self::assertSame('minecraft:diamond', $diamonds->identifier);
        self::assertSame(3, $diamonds->count);
        self::assertSame(17, $pickaxe->damage);
        self::assertSame(4, $pickaxe->auxValue);
        self::assertTrue($nestedNbt->equals($pickaxe->nbt ?? ItemNbt::empty()));
    }

    public function testDuplicateSlotsAreRejected(): void
    {
        $item = LittleEndianNbtTag::compound([
            'Name' => LittleEndianNbtTag::string('minecraft:stone'),
            'Count' => LittleEndianNbtTag::byte(1),
            'Slot' => LittleEndianNbtTag::byte(0),
        ]);
        $nbt = ItemNbt::fromBinary((new LittleEndianNbtCodec())->encodeRootCompound([
            'Items' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, [$item, $item]),
        ]));

        $this->expectException(InvalidArgumentException::class);
        (new ShulkerBoxItemNbtCodec())->decode($nbt, new BlockPosition(0, 64, 0), 1);
    }
}
