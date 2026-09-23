<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Chunk;

use InvalidArgumentException;

final readonly class PreparedChunkLookup
{
    public function __construct(
        public PreparedChunkAvailability $availability,
        public ?PreparedChunk $chunk = null,
        public int $submittedBytes = 0,
    ) {
        if (($availability === PreparedChunkAvailability::READY) !== ($chunk instanceof PreparedChunk)
            || $submittedBytes < 0) {
            throw new InvalidArgumentException('Prepared chunk lookup result is inconsistent.');
        }
    }
}
