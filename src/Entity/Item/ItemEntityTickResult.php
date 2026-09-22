<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Item;

/** Observable entity changes produced by one bounded registry advance. */
final readonly class ItemEntityTickResult
{
    /**
     * @param list<DroppedItemEntity> $updated
     * @param list<DroppedItemEntity> $despawned
     */
    public function __construct(
        public array $updated,
        public array $despawned,
    ) {}
}
