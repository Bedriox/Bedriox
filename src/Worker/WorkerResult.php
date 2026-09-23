<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

final readonly class WorkerResult
{
    public function __construct(
        public WorkerReceipt $receipt,
        public WorkerResultStatus $status,
        public string $payload = '',
        public ?string $failureCode = null,
        public int $completedAtNanoseconds = 0,
    ) {}
}
