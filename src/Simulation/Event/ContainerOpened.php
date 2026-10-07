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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Server\Entity\Mount\AnimalEquipmentSlotDeclaration;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Authoritative projection of one player's newly opened storage window. */
final readonly class ContainerOpened implements WorldEvent
{
    /** @var list<AnimalEquipmentSlotDeclaration> */
    public array $animalEquipmentSlots;

    /**
     * @param list<InventoryStack|null> $slots
     * @param list<string> $blockEventRecipientSessionIds
     * @param array<array-key, mixed> $animalEquipmentSlots
     */
    public function __construct(
        public string $ownerSessionId,
        public int $windowId,
        public ContainerType $containerType,
        public ?BlockPosition $position,
        public array $slots,
        public array $blockEventRecipientSessionIds,
        public ?BlockPosition $pairedPosition = null,
        public ?string $title = null,
        public ?ContainerLayout $layout = null,
        public ?int $entityRuntimeId = null,
        array $animalEquipmentSlots = [],
    ) {
        $entityBacked = in_array($containerType, [ContainerType::CHEST_BOAT, ContainerType::HORSE], true);
        if ($windowId < 2 || $windowId > 99
            || (($containerType === ContainerType::VIRTUAL || $entityBacked) !== ($position === null))
            || ($containerType === ContainerType::VIRTUAL) !== ($layout !== null)
            || $entityBacked !== ($entityRuntimeId !== null)
            || ($entityRuntimeId !== null && $entityRuntimeId < 1)
            || ($containerType !== ContainerType::HORSE && $animalEquipmentSlots !== [])
            || ($title !== null && ($title === '' || strlen($title) > 256 || preg_match('//u', $title) !== 1))) {
            throw new InvalidArgumentException('Storage-container open projection is invalid.');
        }
        $double = $containerType->isPaired();
        $expectedSlots = $containerType === ContainerType::VIRTUAL
            ? $layout?->size()
            : ($containerType === ContainerType::HORSE ? count($slots) : $containerType->slotCount());
        if ($double !== ($pairedPosition !== null)
            || $expectedSlots === null || ($containerType === ContainerType::HORSE && ($expectedSlots < 1 || $expectedSlots > 54))
            || count($slots) !== $expectedSlots) {
            throw new InvalidArgumentException('Storage-container slot projection does not match its type.');
        }
        if ($containerType === ContainerType::HORSE) {
            if ($animalEquipmentSlots === [] || count($animalEquipmentSlots) > $expectedSlots) {
                throw new InvalidArgumentException('Horse-container equipment projection is invalid.');
            }
            $seenEquipmentSlots = [];
            $normalizedEquipmentSlots = [];
            foreach ($animalEquipmentSlots as $index => $equipmentSlot) {
                if ($index !== count($normalizedEquipmentSlots)
                    || !$equipmentSlot instanceof AnimalEquipmentSlotDeclaration
                    || isset($seenEquipmentSlots[$equipmentSlot->slotNumber])) {
                    throw new InvalidArgumentException('Horse-container equipment projection is invalid.');
                }
                $seenEquipmentSlots[$equipmentSlot->slotNumber] = true;
                $normalizedEquipmentSlots[] = $equipmentSlot;
            }
            $animalEquipmentSlots = $normalizedEquipmentSlots;
        }
        $this->animalEquipmentSlots = $animalEquipmentSlots;
    }

    public function recipients(): array
    {
        return array_values(array_unique([$this->ownerSessionId, ...$this->blockEventRecipientSessionIds]));
    }
}
