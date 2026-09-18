<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandJobSubscription;

final readonly class OwnedCommandJobSubscription implements CommandJobSubscription
{
    public function __construct(private CommandRegistry $registry, private int $id) {}

    public function cancel(): void
    {
        $this->registry->cancelJob($this->id);
    }

    public function isRunning(): bool
    {
        return $this->registry->hasJob($this->id);
    }
}
