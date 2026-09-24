<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Command;

use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandValues;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;

final class CommandDispatchedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly CommandSender $sender,
        public readonly string $command,
        public readonly CommandValues $values,
        public readonly string $owner,
        public readonly CommandResult $result,
    ) {}
}
