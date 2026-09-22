<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

use InvalidArgumentException;

/** One canonical item stack produced by authoritative block drops. */
final readonly class BlockDrop
{
    public function __construct(public string $identifier, public int $count)
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) !== 1 || $count < 1 || $count > 64) {
            throw new InvalidArgumentException('Block drop is invalid.');
        }
    }
}
