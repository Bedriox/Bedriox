<?php

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
        $double = in_array($containerType, [ContainerType::DOUBLE_CHEST, ContainerType::DOUBLE_TRAPPED_CHEST], true);
        if ($windowId < 2 || $windowId > 99
            || ($containerType === ContainerType::VIRTUAL) !== ($position === null)
            || ($containerType === ContainerType::VIRTUAL) !== ($layout !== null)
            || $double !== ($pairedPosition !== null)
            || ($containerType === ContainerType::VIRTUAL && count($slots) !== $layout?->size())
            || ($containerType !== ContainerType::VIRTUAL && !in_array(count($slots), [27, 54], true))
            || ($double && count($slots) !== 54)
            || (!$double && $containerType !== ContainerType::VIRTUAL && count($slots) !== 27)) {
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
