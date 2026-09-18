<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Block;

use InvalidArgumentException;

/** Process-local dense block-state identifier; never valid on the Bedrock wire or in persistent storage. */
final readonly class InternalBlockStateId
{
    public function __construct(public int $value)
    {
        if ($value < 0 || $value > 0x7fffffff) {
            throw new InvalidArgumentException('Internal block-state ID must fit in a non-negative signed 32-bit integer.');
        }
    }
}
