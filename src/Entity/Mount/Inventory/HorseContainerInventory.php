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

namespace Bedriox\Server\Entity\Mount\Inventory;

use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Mount\AnimalEquipmentSlotDeclaration;
use Bedriox\Server\Entity\Mount\AnimalEquipmentSlotType;
use Bedriox\Server\Entity\Mount\AnimalStorageInventoryOwner;
use Bedriox\Server\Entity\Mount\HorseFamilyEntity;
use Bedriox\Server\Entity\Mount\UndeadHorseEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\TraderLlamaEntity;
use Bedriox\Server\Inventory\AbstractContainerInventory;
use InvalidArgumentException;

/**
 * Authoritative composite horse window. Equipment occupies the leading slots in declaration order,
 * followed by the currently exposed chest-storage slots.
 */
final class HorseContainerInventory extends AbstractContainerInventory
{
    /** @var non-empty-list<AnimalEquipmentSlotDeclaration> */
    private readonly array $equipmentSlots;

    /** @param array<array-key, mixed> $equipmentSlots */
    public function __construct(
        private readonly HorseFamilyEntity|UndeadHorseEntity $entity,
        array $equipmentSlots,
        private readonly ?AnimalStorageInventoryOwner $storage,
    ) {
        if ($equipmentSlots === []) {
            throw new InvalidArgumentException('Horse containers require a bounded equipment declaration.');
        }
        $normalized = [];
        foreach ($equipmentSlots as $index => $slot) {
            if ($index !== count($normalized) || !$slot instanceof AnimalEquipmentSlotDeclaration) {
                throw new InvalidArgumentException('Horse equipment declarations must be typed.');
            }
            $normalized[] = $slot;
        }
        $this->equipmentSlots = $normalized;
        parent::__construct('entity:horse:' . $entity->getUniqueId());
    }

    public function size(): int
    {
        return count($this->equipmentSlots) + ($this->storage?->getStorageSlotCount() ?? 0);
    }

    public function belongsTo(HorseFamilyEntity|UndeadHorseEntity $entity): bool
    {
        return $this->entity === $entity;
    }

    public function revision(): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, pack('V', $this->size()));
        foreach ($this->contents() as $slot => $stack) {
            hash_update($hash, pack('V', $slot));
            if ($stack === null) {
                hash_update($hash, "\0");
                continue;
            }
            hash_update($hash, "\1" . pack('V', strlen($stack->identifier)) . $stack->identifier);
            hash_update($hash, pack('V3', $stack->count, $stack->damage, $stack->auxValue));
            $nbt = $stack->nbt?->toBinary() ?? '';
            hash_update($hash, pack('V', strlen($nbt)) . $nbt);
        }
        hash_update($hash, $this->storage?->storageInventory()->revision() ?? '-');

        return hash_final($hash);
    }

    public function stackAt(int $slot): ?ItemStack
    {
        self::validateSlot($slot, $this->size());

        return $this->contents()[$slot];
    }

    public function contents(): array
    {
        $contents = [];
        foreach ($this->equipmentSlots as $slot) {
            $contents[] = $this->equippedItem($slot);
        }
        if ($this->storage !== null) {
            $visibleStorage = $this->storage->getStorageSlotCount();
            foreach (array_slice($this->storage->storageInventory()->contents(), 0, $visibleStorage) as $stack) {
                $contents[] = $stack;
            }
        }

        return $contents;
    }

    public function setStack(int $slot, ?ItemStack $stack, ?string $expectedRevision = null): bool
    {
        self::validateSlot($slot, $this->size());
        $contents = $this->contents();
        $contents[$slot] = $stack;

        return $this->replaceContents(array_values($contents), $expectedRevision);
    }

    public function replaceContents(array $contents, ?string $expectedRevision = null): bool
    {
        $this->assertRevision($expectedRevision);
        if (count($contents) !== $this->size()) {
            throw new InvalidArgumentException('Horse-container contents must preserve their composite layout.');
        }
        $equipmentCount = count($this->equipmentSlots);
        foreach ($this->equipmentSlots as $index => $declaration) {
            $stack = $contents[$index];
            if ($stack !== null && ($stack->count !== 1
                    || !in_array($stack->identifier, $declaration->acceptedItemIdentifiers, true))) {
                throw new InvalidArgumentException('Horse-container equipment does not fit its declared slot.');
            }
        }
        $storageContents = array_slice($contents, $equipmentCount);
        $changed = false;
        $storage = $this->storage;
        if ($storage !== null && $storageContents !== []) {
            $allStorage = $storage->storageInventory()->contents();
            array_splice($allStorage, 0, count($storageContents), $storageContents);
            if ($storage->storageInventory()->replaceContents($allStorage)) {
                $storage->markStorageInventoryChanged();
                $changed = true;
            }
        }
        foreach ($this->equipmentSlots as $index => $declaration) {
            $before = $this->equippedItem($declaration);
            $after = $contents[$index];
            if ($before == $after) {
                continue;
            }
            $this->setEquippedItem($declaration, $after);
            $changed = true;
        }

        return $changed;
    }

    public function isDirty(): bool
    {
        return $this->storage?->storageInventory()->isDirty() ?? false;
    }

    public function acknowledgePersistedRevision(string $revision): bool
    {
        if (!hash_equals($this->revision(), $revision)) {
            return false;
        }

        $storage = $this->storage;
        if ($storage === null) {
            return true;
        }

        return $storage->storageInventory()->acknowledgePersistedRevision(
            $storage->storageInventory()->revision(),
        );
    }

    private function equippedItem(AnimalEquipmentSlotDeclaration $slot): ?ItemStack
    {
        return match ($slot->type) {
            AnimalEquipmentSlotType::SADDLE => $this->entity->isSaddled()
                ? new ItemStack('minecraft:saddle', 1)
                : null,
            AnimalEquipmentSlotType::HORSE_ARMOR => $this->entity->getHorseArmor(),
            AnimalEquipmentSlotType::CARPET => ($this->entity instanceof LlamaEntity
                || $this->entity instanceof TraderLlamaEntity) && $this->entity->getCarpetColor() !== null
                ? new ItemStack('minecraft:' . $this->entity->getCarpetColor()->value . '_carpet', 1)
                : null,
        };
    }

    private function setEquippedItem(AnimalEquipmentSlotDeclaration $slot, ?ItemStack $item): void
    {
        switch ($slot->type) {
            case AnimalEquipmentSlotType::SADDLE:
                $this->entity->setSaddled($item !== null);
                break;
            case AnimalEquipmentSlotType::HORSE_ARMOR:
                $this->entity->setHorseArmor($item);
                break;
            case AnimalEquipmentSlotType::CARPET:
                $this->setCarpet($item);
                break;
        }
    }

    private function setCarpet(?ItemStack $item): void
    {
        if (!$this->entity instanceof LlamaEntity && !$this->entity instanceof TraderLlamaEntity) {
            throw new InvalidArgumentException('This horse-family entity cannot wear carpet.');
        }
        $color = $item === null
            ? null
            : WoolColor::tryFrom(substr($item->identifier, strlen('minecraft:'), -strlen('_carpet')));
        if ($item !== null && $color === null) {
            throw new InvalidArgumentException('The selected llama carpet is unsupported.');
        }
        $this->entity->setCarpetColor($color);
    }
}
