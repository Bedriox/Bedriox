<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventoryStackRequestAction;

final readonly class ApplyInventoryStackRequest implements WorldCommand
{
    /** @param list<InventoryStackRequestAction> $actions */
    public function __construct(
        public string $session,
        public int $requestId,
        public array $actions,
        public ?string $rejectionReason = null,
        public InventoryResponseMode $responseMode = InventoryResponseMode::ItemStackResponse,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 32 + strlen($this->session) + strlen($this->rejectionReason ?? '') + count($this->actions) * 48;
    }
}
