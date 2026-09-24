<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Command;

use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Event\CancellableEvent;

final class CommandPreDispatchEvent extends CancellableEvent
{
    public function __construct(
        public readonly CommandSender $sender,
        public readonly string $command,
        public readonly CommandValues $values,
        public readonly string $owner,
    ) {}
}
