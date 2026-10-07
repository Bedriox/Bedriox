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
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Authoritative storage contents ready for one or more active viewer windows. */
final readonly class ContainerContentsChanged implements WorldEvent
{
    /**
     * @param list<InventoryStack|null> $slots
     * @param list<int> $changedSlots Empty means that every slot must be synchronized.
     * @param list<ContainerViewerProjection> $additionalViewers
     */
    public function __construct(
        public string $ownerSessionId,
        public int $windowId,
        public ContainerType $containerType,
        public ?BlockPosition $position,
        public array $slots,
        public array $changedSlots = [],
        public array $additionalViewers = [],
        public ?BlockPosition $pairedPosition = null,
        public ?ContainerLayout $layout = null,
    ) {
        $double = $containerType->isPaired();
        $expectedSlots = $containerType === ContainerType::VIRTUAL
            ? $layout?->size()
            : ($containerType === ContainerType::HORSE ? count($slots) : $containerType->slotCount());
        if ($windowId < 2 || $windowId > 99
            || ((in_array($containerType, [ContainerType::VIRTUAL, ContainerType::CHEST_BOAT, ContainerType::HORSE], true))
                !== ($position === null))
            || ($containerType === ContainerType::VIRTUAL) !== ($layout !== null)
            || $double !== ($pairedPosition !== null)
            || $expectedSlots === null || ($containerType === ContainerType::HORSE && ($expectedSlots < 1 || $expectedSlots > 54))
            || count($slots) !== $expectedSlots) {
            throw new InvalidArgumentException('Storage-container content projection is invalid.');
        }
        $seen = [];
        foreach ($changedSlots as $slot) {
            if ($slot < 0 || $slot >= count($slots) || isset($seen[$slot])) {
                throw new InvalidArgumentException('Storage-container changed-slot projection is invalid.');
            }
            $seen[$slot] = true;
        }
        $sessions = [$ownerSessionId => true];
        foreach ($additionalViewers as $viewer) {
            if (count($viewer->slots) !== count($slots) || isset($sessions[$viewer->sessionId])) {
                throw new InvalidArgumentException('Storage-container viewer window mapping is invalid.');
            }
            $sessions[$viewer->sessionId] = true;
        }
    }

    public function recipients(): array
    {
        return [$this->ownerSessionId, ...array_map(
            static fn(ContainerViewerProjection $viewer): string => $viewer->sessionId,
            $this->additionalViewers,
        )];
    }
}
