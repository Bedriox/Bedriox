<?php

declare(strict_types=1);

namespace Bedriox\Api\Scheduler;

final class TaskRejectedException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?TaskHandle $handle = null)
    {
        parent::__construct($message);
    }
}
