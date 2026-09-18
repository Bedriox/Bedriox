<?php

declare(strict_types=1);

namespace Bedriox\Api\Command;

interface CommandSender
{
    public function type(): CommandSenderType;

    public function name(): string;

    public function sendMessage(string $message): void;

    public function hasPermission(string $permission): bool;
}
