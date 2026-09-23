<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

final readonly class WorkerSubmission
{
    private function __construct(
        public ?WorkerReceipt $receipt,
        public ?WorkerRejectionReason $rejection,
    ) {}

    public static function accepted(WorkerReceipt $receipt): self
    {
        return new self($receipt, null);
    }

    public static function rejected(WorkerRejectionReason $reason): self
    {
        return new self(null, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->receipt !== null;
    }
}
