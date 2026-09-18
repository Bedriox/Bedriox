<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandSubscription;

final readonly class OwnedCommandSubscription implements CommandSubscription
{
    public function __construct(private CommandRegistry $registry, private int $id) {}

    public function unregister(): void
    {
        $this->registry->unregister($this->id);
    }

    public function isRegistered(): bool
    {
        return $this->registry->has($this->id);
    }
}
