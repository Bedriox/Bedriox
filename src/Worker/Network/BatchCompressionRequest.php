<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\CompressionMode;

final readonly class BatchCompressionRequest
{
    public function __construct(
        public string $uncompressedBatch,
        public CompressionMode $mode,
        public int $threshold,
        public BatchLimits $limits,
    ) {}
}
