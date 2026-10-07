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

namespace Bedriox\Server\Entity\Mount\State;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Inventory\SimpleContainerInventory;
use InvalidArgumentException;

trait AnimalStorageState
{
    private SimpleContainerInventory $animalStorageInventory;

    final public function storageInventory(): SimpleContainerInventory
    {
        return $this->animalStorageInventory;
    }

    final public function markStorageInventoryChanged(): void
    {
        $this->markChanged();
    }

    /** @return list<ItemStack> @internal Transfers stored items exactly once during authoritative destruction. */
    final public function drainStorageItems(): array
    {
        $items = array_values(array_filter(
            $this->animalStorageInventory->contents(),
            static fn(?ItemStack $stack): bool => $stack !== null,
        ));
        if ($items !== []) {
            $this->animalStorageInventory->replaceContents(array_fill(
                0,
                $this->animalStorageInventory->size(),
                null,
            ));
            $this->markChanged();
        }

        return $items;
    }

    final protected function initializeAnimalStorage(string $uniqueId, int $slots): void
    {
        $this->animalStorageInventory = new SimpleContainerInventory('entity:animal:' . $uniqueId, $slots);
    }

    final protected function resizeEmptyAnimalStorage(int $slots): void
    {
        $this->animalStorageInventory = new SimpleContainerInventory(
            $this->animalStorageInventory->identifier(),
            $slots,
        );
    }

    /** @return list<array{identifier: string, count: int, damage: int, auxValue: int, nbt: ?string}|null> */
    final protected function animalStoragePersistenceData(): array
    {
        return array_map(
            static fn(?ItemStack $stack): ?array => $stack === null ? null : [
                'identifier' => $stack->identifier,
                'count' => $stack->count,
                'damage' => $stack->damage,
                'auxValue' => $stack->auxValue,
                'nbt' => $stack->nbt === null ? null : base64_encode($stack->nbt->toBinary()),
            ],
            $this->animalStorageInventory->contents(),
        );
    }

    /** @param mixed $encodedItems */
    final protected function restoreAnimalStorage(mixed $encodedItems): void
    {
        if (!is_array($encodedItems) || !array_is_list($encodedItems)
            || count($encodedItems) !== $this->animalStorageInventory->size()) {
            throw new InvalidArgumentException('Persisted animal storage is malformed.');
        }
        $items = [];
        foreach ($encodedItems as $encoded) {
            if ($encoded === null) {
                $items[] = null;
                continue;
            }
            if (!is_array($encoded) || array_keys($encoded) !== ['identifier', 'count', 'damage', 'auxValue', 'nbt']
                || !is_string($encoded['identifier']) || !is_int($encoded['count'])
                || !is_int($encoded['damage']) || !is_int($encoded['auxValue'])
                || ($encoded['nbt'] !== null && !is_string($encoded['nbt']))) {
                throw new InvalidArgumentException('Persisted animal storage item is malformed.');
            }
            $binary = $encoded['nbt'] === null ? null : base64_decode($encoded['nbt'], true);
            if ($encoded['nbt'] !== null && $binary === false) {
                throw new InvalidArgumentException('Persisted animal storage item NBT is malformed.');
            }
            $items[] = new ItemStack(
                $encoded['identifier'],
                $encoded['count'],
                $encoded['damage'],
                $binary === null ? null : ItemNbt::fromBinary($binary),
                $encoded['auxValue'],
            );
        }
        $this->animalStorageInventory->replaceContents($items);
    }
}
