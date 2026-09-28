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

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Protocol\Exception\InvalidValueException;
use Bedriox\Protocol\Packet\LittleEndianNbtToNetwork;
use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use Bedriox\Server\World\BlockEntity\BlockEntityRegistry;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use InvalidArgumentException;

/** Mojang LevelDB block-entity records: concatenated unnamed little-endian NBT compounds. */
final readonly class PersistentBlockEntityCodec
{
    public const int MAXIMUM_BYTES = 4_194_304;
    public const int MAXIMUM_NETWORK_BYTES = 1_048_576;

    public function __construct(
        private BlockEntityRegistry $registry = new BlockEntityRegistry(),
        private LittleEndianNbtCodec $nbt = new LittleEndianNbtCodec(),
    ) {}

    public function encode(BlockEntityCollection $entities): string
    {
        $roots = [];
        foreach ($entities->all() as $entity) {
            $roots[] = $this->encodeEntity($entity);
        }
        try {
            $encoded = $this->nbt->encodeRootCompounds($roots, BlockEntityCollection::MAXIMUM_ENTITIES);
        } catch (InvalidArgumentException $error) {
            throw new LevelDbStorageException('Block entities cannot be represented as bounded NBT.', previous: $error);
        }
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new LevelDbStorageException('Encoded block entities exceed their byte limit.');
        }

        return $encoded;
    }

    public function decode(string $encoded, ChunkPosition $position): BlockEntityCollection
    {
        if (strlen($encoded) > self::MAXIMUM_BYTES) {
            throw new LevelDbStorageException('Stored block entities exceed their byte limit.');
        }
        if ($encoded === '') {
            return new BlockEntityCollection($position);
        }
        try {
            $roots = $this->nbt->decodeRootCompounds($encoded, BlockEntityCollection::MAXIMUM_ENTITIES);
            $entities = [];
            foreach ($roots as $root) {
                $entities[] = $this->decodeEntity($root);
            }

            return new BlockEntityCollection($position, $entities);
        } catch (CorruptWorldDataException|InvalidArgumentException $error) {
            throw new LevelDbStorageException('Stored block entities contain malformed or unsupported data.', previous: $error);
        }
    }

    /**
     * Projects client-visible block-actor state as bounded Bedrock network-NBT compounds.
     * Container contents remain authoritative server state and are synchronized through inventory packets.
     *
     * @return list<string>
     */
    public function encodeNetwork(BlockEntityCollection $entities): array
    {
        $encoded = [];
        $totalBytes = 0;
        foreach ($entities->all() as $entity) {
            $networkNbt = $this->encodeNetworkEntity($entity);
            $totalBytes += strlen($networkNbt);
            if ($totalBytes > self::MAXIMUM_NETWORK_BYTES) {
                throw new LevelDbStorageException('Encoded network block entities exceed their byte limit.');
            }
            $encoded[] = $networkNbt;
        }

        return $encoded;
    }

    public function encodeNetworkEntity(BlockEntity $entity): string
    {
        try {
            return LittleEndianNbtToNetwork::convert($this->nbt->encodeRootCompound(
                $this->encodeNetworkEntityTags($entity),
            ));
        } catch (InvalidArgumentException|InvalidValueException $error) {
            throw new LevelDbStorageException(
                'Block entity cannot be represented as bounded Bedrock network NBT.',
                previous: $error,
            );
        }
    }

    /** @return array<string, LittleEndianNbtTag> */
    private function encodeEntity(BlockEntity $entity): array
    {
        $root = [
            'id' => LittleEndianNbtTag::string($this->registry->persistentId($entity->type)),
            'x' => LittleEndianNbtTag::int($entity->position->x),
            'y' => LittleEndianNbtTag::int($entity->position->y),
            'z' => LittleEndianNbtTag::int($entity->position->z),
            'isMovable' => LittleEndianNbtTag::byte(1),
        ];
        if (!$entity instanceof ContainerBlockEntity) {
            return $root;
        }
        if ($entity->customName !== null) {
            $root['CustomName'] = LittleEndianNbtTag::string($entity->customName);
        }
        $items = [];
        foreach ($entity->inventory->contents() as $slot => $stack) {
            $items[] = LittleEndianNbtTag::compound($this->encodeItem($slot, $stack));
        }
        $root['Items'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $items);
        if ($entity->type === BlockEntityType::Chest && $entity->pairedPosition !== null) {
            $root['pairx'] = LittleEndianNbtTag::int($entity->pairedPosition->x);
            $root['pairz'] = LittleEndianNbtTag::int($entity->pairedPosition->z);
            $root['pairlead'] = LittleEndianNbtTag::byte($entity->pairLead ? 1 : 0);
        }
        if ($entity->type === BlockEntityType::ShulkerBox) {
            $root['facing'] = LittleEndianNbtTag::byte($entity->facing);
        }

        return $root;
    }

    /** @return array<string, LittleEndianNbtTag> */
    private function encodeNetworkEntityTags(BlockEntity $entity): array
    {
        $root = [
            'id' => LittleEndianNbtTag::string($this->registry->persistentId($entity->type)),
            'x' => LittleEndianNbtTag::int($entity->position->x),
            'y' => LittleEndianNbtTag::int($entity->position->y),
            'z' => LittleEndianNbtTag::int($entity->position->z),
        ];
        if (!$entity instanceof ContainerBlockEntity) {
            return $root;
        }
        if ($entity->customName !== null) {
            $root['CustomName'] = LittleEndianNbtTag::string($entity->customName);
        }
        if ($entity->type === BlockEntityType::Chest && $entity->pairedPosition !== null) {
            $root['pairx'] = LittleEndianNbtTag::int($entity->pairedPosition->x);
            $root['pairz'] = LittleEndianNbtTag::int($entity->pairedPosition->z);
        }
        if ($entity->type === BlockEntityType::ShulkerBox) {
            $root['facing'] = LittleEndianNbtTag::byte($entity->facing);
        }

        return $root;
    }

    /** @param array<string, LittleEndianNbtTag> $root */
    private function decodeEntity(array $root): BlockEntity
    {
        $type = $this->registry->fromPersistentId(self::string(self::required($root, 'id'), 'id'));
        $position = new BlockPosition(
            self::integer(self::required($root, 'x'), LittleEndianNbtTag::INT, 'x'),
            self::integer(self::required($root, 'y'), LittleEndianNbtTag::INT, 'y'),
            self::integer(self::required($root, 'z'), LittleEndianNbtTag::INT, 'z'),
        );
        if (!$type->ownsPersistentInventory()) {
            return $this->registry->create($type, $position);
        }
        $contents = [];
        $items = $root['Items'] ?? null;
        if ($items !== null) {
            if ($items->type !== LittleEndianNbtTag::LIST || $items->listType !== LittleEndianNbtTag::COMPOUND
                || !is_array($items->value) || count($items->value) > ContainerBlockEntity::STORAGE_SLOT_COUNT) {
                throw new InvalidArgumentException('Block-entity Items tag is malformed or exceeds its slot limit.');
            }
            foreach ($items->value as $entry) {
                if (!$entry instanceof LittleEndianNbtTag || $entry->type !== LittleEndianNbtTag::COMPOUND
                    || !is_array($entry->value)) {
                    throw new InvalidArgumentException('Block-entity Items tag contains an invalid entry.');
                }
                [$slot, $stack] = $this->decodeItem(self::compound($entry, 'Items'));
                if (isset($contents[$slot])) {
                    throw new InvalidArgumentException('Block-entity Items tag contains a duplicate slot.');
                }
                $contents[$slot] = $stack;
            }
        }
        $customName = isset($root['CustomName']) ? self::string($root['CustomName'], 'CustomName') : null;
        if ($customName === '') {
            $customName = null;
        }
        $pairedPosition = null;
        $pairLead = false;
        if (isset($root['pairx']) || isset($root['pairz'])) {
            $pairedPosition = new BlockPosition(
                self::integer(self::required($root, 'pairx'), LittleEndianNbtTag::INT, 'pairx'),
                $position->y,
                self::integer(self::required($root, 'pairz'), LittleEndianNbtTag::INT, 'pairz'),
            );
            $pairLead = isset($root['pairlead'])
                && self::integer($root['pairlead'], LittleEndianNbtTag::BYTE, 'pairlead') !== 0;
        }
        $facing = isset($root['facing'])
            ? self::integer($root['facing'], LittleEndianNbtTag::BYTE, 'facing')
            : 2;

        return new ContainerBlockEntity(
            $type,
            $position,
            new ContainerInventory(ContainerBlockEntity::STORAGE_SLOT_COUNT, $contents),
            $customName,
            $pairedPosition,
            $pairLead,
            $facing,
        );
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
        $custom = $stack->nbt === null
            ? []
            : $this->nbt->decodeRootCompound($stack->nbt->toBinary());
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
            throw new InvalidArgumentException('Block-entity item slot is outside the container range.');
        }
        $identifier = self::string(self::required($item, 'Name'), 'Items.Name');
        $count = self::integer(self::required($item, 'Count'), LittleEndianNbtTag::BYTE, 'Items.Count');
        $auxValue = isset($item['Damage'])
            ? self::integer($item['Damage'], LittleEndianNbtTag::SHORT, 'Items.Damage')
            : 0;
        $damage = 0;
        $nbt = null;
        if (isset($item['tag'])) {
            $custom = self::compound($item['tag'], 'Items.tag');
            if (isset($custom['Damage'])) {
                $damage = self::integer($custom['Damage'], LittleEndianNbtTag::INT, 'Items.tag.Damage');
                unset($custom['Damage']);
            }
            if ($custom !== []) {
                $nbt = ItemNbt::fromBinary($this->nbt->encodeRootCompound($custom));
            }
        }

        return [$slot, new ContainerItemStack($identifier, $count, $damage, $nbt, $auxValue)];
    }

    /** @param array<string, LittleEndianNbtTag> $tags */
    private static function required(array $tags, string $name): LittleEndianNbtTag
    {
        return $tags[$name] ?? throw new InvalidArgumentException("Block-entity NBT is missing tag '$name'.");
    }

    private static function integer(LittleEndianNbtTag $tag, int $type, string $name): int
    {
        if ($tag->type !== $type || !is_int($tag->value)) {
            throw new InvalidArgumentException("Block-entity NBT tag '$name' has the wrong type.");
        }

        return $tag->value;
    }

    private static function string(LittleEndianNbtTag $tag, string $name): string
    {
        if ($tag->type !== LittleEndianNbtTag::STRING || !is_string($tag->value)) {
            throw new InvalidArgumentException("Block-entity NBT tag '$name' has the wrong type.");
        }

        return $tag->value;
    }

    /** @return array<string, LittleEndianNbtTag> */
    private static function compound(LittleEndianNbtTag $tag, string $name): array
    {
        if ($tag->type !== LittleEndianNbtTag::COMPOUND || !is_array($tag->value)) {
            throw new InvalidArgumentException("Block-entity NBT tag '$name' has the wrong type.");
        }
        $result = [];
        foreach ($tag->value as $key => $entry) {
            if (!is_string($key) || !$entry instanceof LittleEndianNbtTag) {
                throw new InvalidArgumentException("Block-entity NBT tag '$name' contains an invalid entry.");
            }
            $result[$key] = $entry;
        }

        return $result;
    }
}
