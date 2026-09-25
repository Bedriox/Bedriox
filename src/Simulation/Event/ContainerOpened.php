<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Authoritative projection of one player's newly opened storage window. */
final readonly class ContainerOpened implements WorldEvent
{
    /**
     * @param list<InventoryStack|null> $slots
     * @param list<string> $blockEventRecipientSessionIds
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
    ) {
        if ($windowId < 2 || $windowId > 99
            || ($containerType === ContainerType::VIRTUAL) !== ($position === null)
            || ($containerType === ContainerType::VIRTUAL) !== ($layout !== null)
            || ($title !== null && ($title === '' || strlen($title) > 256 || preg_match('//u', $title) !== 1))) {
            throw new InvalidArgumentException('Storage-container open projection is invalid.');
        }
        $double = in_array($containerType, [ContainerType::DOUBLE_CHEST, ContainerType::DOUBLE_TRAPPED_CHEST], true);
        if ($double !== ($pairedPosition !== null)
            || ($containerType === ContainerType::VIRTUAL && count($slots) !== $layout?->size())
            || ($containerType !== ContainerType::VIRTUAL && !in_array(count($slots), [27, 54], true))
            || ($double && count($slots) !== 54)
            || (!$double && $containerType !== ContainerType::VIRTUAL && count($slots) !== 27)) {
            throw new InvalidArgumentException('Storage-container slot projection does not match its type.');
        }
    }

    public function recipients(): array
    {
        return array_values(array_unique([$this->ownerSessionId, ...$this->blockEventRecipientSessionIds]));
    }
}
