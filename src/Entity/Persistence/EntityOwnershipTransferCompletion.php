<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

final readonly class EntityOwnershipTransferCompletion
{
    public function __construct(
        public EntityOwnershipTransfer $transfer,
        public bool $successful,
        public ?string $failureCode = null,
        public ?string $failureDetail = null,
        public ?EntityOwnershipTransferResult $result = null,
    ) {
        if ($successful === ($failureCode !== null)
            || ($successful && $failureDetail !== null)
            || $successful !== ($result instanceof EntityOwnershipTransferResult)) {
            throw new \InvalidArgumentException('Entity ownership transfer completion result is inconsistent.');
        }
    }
}
