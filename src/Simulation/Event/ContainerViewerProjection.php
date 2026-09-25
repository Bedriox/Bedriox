<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use InvalidArgumentException;

/** One viewer's session-local window and stack-network identity projection. */
final readonly class ContainerViewerProjection
{
    /** @param list<InventoryStack|null> $slots */
    public function __construct(
        public string $sessionId,
        public int $windowId,
        public array $slots,
    ) {
        if ($sessionId === '' || $windowId < 2 || $windowId > 99) {
            throw new InvalidArgumentException('Storage-container viewer projection is invalid.');
        }
    }
}
