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

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use InvalidArgumentException;

/** Converts portable shulker-box inventory data between a block entity and its dropped item. */
final readonly class ShulkerBoxItemNbtCodec
{
    public function __construct(private LittleEndianNbtCodec $nbt = new LittleEndianNbtCodec()) {}

    public function encode(ContainerBlockEntity $entity): ItemNbt
    {
        if ($entity->type !== BlockEntityType::ShulkerBox) {
            throw new InvalidArgumentException('Only a shulker-box block entity can be stored in shulker item NBT.');
        }
        $items = [];
        foreach ($entity->inventory->contents() as $slot => $stack) {
            $items[] = LittleEndianNbtTag::compound($this->encodeItem($slot, $stack));
        }
        $root = [
            'Items' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $items),
        ];
        if ($entity->customName !== null) {
            $root['CustomName'] = LittleEndianNbtTag::string($entity->customName);
        }

        return ItemNbt::fromBinary($this->nbt->encodeRootCompound($root));
    }

    public function decode(?ItemNbt $nbt, BlockPosition $position, int $facing): ContainerBlockEntity
    {
        if ($nbt === null) {
            return ContainerBlockEntity::empty(BlockEntityType::ShulkerBox, $position)->withFacing($facing);
        }
        try {
            $root = $this->nbt->decodeRootCompound($nbt->toBinary());
            $contents = [];
            $items = $root['Items'] ?? null;
            if ($items !== null) {
                if ($items->type !== LittleEndianNbtTag::LIST
                    || $items->listType !== LittleEndianNbtTag::COMPOUND
                    || !is_array($items->value)
                    || count($items->value) > ContainerBlockEntity::STORAGE_SLOT_COUNT) {
                    throw new InvalidArgumentException('Shulker item Items tag is malformed or exceeds its slot limit.');
                }
                foreach ($items->value as $entry) {
                    if (!$entry instanceof LittleEndianNbtTag
                        || $entry->type !== LittleEndianNbtTag::COMPOUND
                        || !is_array($entry->value)) {
                        throw new InvalidArgumentException('Shulker item contains a malformed inventory entry.');
                    }
                    [$slot, $stack] = $this->decodeItem(self::compound($entry, 'Items'));
                    if (isset($contents[$slot])) {
                        throw new InvalidArgumentException('Shulker item contains a duplicate inventory slot.');
                    }
                    $contents[$slot] = $stack;
                }
            }
            $customName = isset($root['CustomName']) ? self::string($root['CustomName'], 'CustomName') : null;
            if ($customName === '') {
                $customName = null;
            }

            return new ContainerBlockEntity(
                BlockEntityType::ShulkerBox,
                $position,
                new ContainerInventory(ContainerBlockEntity::STORAGE_SLOT_COUNT, $contents),
                $customName,
                facing: $facing,
            );
        } catch (CorruptWorldDataException $error) {
            throw new InvalidArgumentException('Shulker item NBT is malformed.', previous: $error);
        }
    }

    /** @return array<string, LittleEndianNbtTag> */
    private function encodeItem(int $slot, ContainerItemStack $stack): array
    {
        $item = [
            'Name' => LittleEndianNbtTag::string($stack->identifier),
            'Count' => LittleEndianNbtTag::byte($stack->count),
            'Damage' => new LittleEndianNbtTag(LittleEndianNbtTag::SHORT, $stack->auxValue),
            'Slot' => LittleEndianNbtTag::byte($slot),
        ];
        $custom = $stack->nbt === null ? [] : $this->nbt->decodeRootCompound($stack->nbt->toBinary());
        unset($custom['Damage']);
        if ($stack->damage !== 0) {
            $custom['Damage'] = LittleEndianNbtTag::int($stack->damage);
        }
        if ($custom !== []) {
            $item['tag'] = LittleEndianNbtTag::compound($custom);
        }

        return $item;
    }

    /**
     * @param array<string, LittleEndianNbtTag> $item
     * @return array{int, ContainerItemStack}
     */
    private function decodeItem(array $item): array
    {
        $slot = self::integer(self::required($item, 'Slot'), LittleEndianNbtTag::BYTE, 'Items.Slot');
        if ($slot < 0 || $slot >= ContainerBlockEntity::STORAGE_SLOT_COUNT) {
            throw new InvalidArgumentException('Shulker item slot is outside the container.');
        }
        $identifier = self::string(self::required($item, 'Name'), 'Items.Name');
        $count = self::integer(self::required($item, 'Count'), LittleEndianNbtTag::BYTE, 'Items.Count');
        $auxValue = isset($item['Damage'])
            ? self::integer($item['Damage'], LittleEndianNbtTag::SHORT, 'Items.Damage')
            : 0;
        $damage = 0;
        $customNbt = null;
        if (isset($item['tag'])) {
            $custom = self::compound($item['tag'], 'Items.tag');
            if (isset($custom['Damage'])) {
                $damage = self::integer($custom['Damage'], LittleEndianNbtTag::INT, 'Items.tag.Damage');
                unset($custom['Damage']);
            }
            if ($custom !== []) {
                $customNbt = ItemNbt::fromBinary($this->nbt->encodeRootCompound($custom));
            }
        }

        return [$slot, new ContainerItemStack($identifier, $count, $damage, $customNbt, $auxValue)];
    }

    /** @param array<string, LittleEndianNbtTag> $tags */
    private static function required(array $tags, string $name): LittleEndianNbtTag
    {
        return $tags[$name] ?? throw new InvalidArgumentException("Shulker item NBT is missing tag '$name'.");
    }

    private static function integer(LittleEndianNbtTag $tag, int $type, string $name): int
    {
        if ($tag->type !== $type || !is_int($tag->value)) {
            throw new InvalidArgumentException("Shulker item NBT tag '$name' has the wrong type.");
        }

        return $tag->value;
    }

    private static function string(LittleEndianNbtTag $tag, string $name): string
    {
        if ($tag->type !== LittleEndianNbtTag::STRING || !is_string($tag->value)) {
            throw new InvalidArgumentException("Shulker item NBT tag '$name' has the wrong type.");
        }

        return $tag->value;
    }

    /** @return array<string, LittleEndianNbtTag> */
    private static function compound(LittleEndianNbtTag $tag, string $name): array
    {
        if ($tag->type !== LittleEndianNbtTag::COMPOUND || !is_array($tag->value)) {
            throw new InvalidArgumentException("Shulker item NBT tag '$name' has the wrong type.");
        }
        $result = [];
        foreach ($tag->value as $key => $entry) {
            if (!is_string($key) || !$entry instanceof LittleEndianNbtTag) {
                throw new InvalidArgumentException("Shulker item NBT tag '$name' contains an invalid entry.");
            }
            $result[$key] = $entry;
        }

        return $result;
    }
}
