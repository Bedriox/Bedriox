<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\ConsoleCommandSender;
use Bedriox\Server\Observability\ServerLogger;
use InvalidArgumentException;

final readonly class ServerConsoleCommandSender implements ConsoleCommandSender
{
    public function __construct(private ServerLogger $logger) {}

    public function type(): CommandSenderType
    {
        return CommandSenderType::CONSOLE;
    }

    public function name(): string
    {
        return 'CONSOLE';
    }

    public function sendMessage(string $message): void
    {
        if ($message === '' || strlen($message) > 4096 || preg_match('//u', $message) !== 1 || str_contains($message, "\0")) {
            throw new InvalidArgumentException('Command output must be valid UTF-8 between 1 and 4096 bytes.');
        }
        $this->logger->info($message, 'Command');
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }
}
