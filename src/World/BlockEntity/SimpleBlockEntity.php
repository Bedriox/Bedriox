<?php

declare(strict_types=1);

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** A block entity whose durable state is limited to its identity and position. */
final readonly class SimpleBlockEntity extends BlockEntity
{
    public function __construct(BlockEntityType $type, BlockPosition $position, int $revision = 0)
    {
        parent::__construct($type, $position, $revision);
        if ($type !== BlockEntityType::EnderChest) {
            throw new InvalidArgumentException('The requested block-entity type requires specialized durable state.');
        }
    }
}
