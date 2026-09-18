<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Player\Player;
use Closure;
use InvalidArgumentException;

/** @internal Composes future in-game command delivery without exposing mutable player state. */
final readonly class ServerPlayerCommandSender implements PlayerCommandSender
{
    /**
     * @param Closure(string): void $messageSink
     * @param Closure(string): bool $permissionResolver
     */
    public function __construct(
        private Player $player,
        private Closure $messageSink,
        private Closure $permissionResolver,
    ) {}

    public function type(): CommandSenderType
    {
        return CommandSenderType::PLAYER;
    }

    public function name(): string
    {
        return $this->player->name;
    }

    public function sendMessage(string $message): void
    {
        if ($message === '' || strlen($message) > 4096 || preg_match('//u', $message) !== 1 || str_contains($message, "\0")) {
            throw new InvalidArgumentException('Command output must be valid UTF-8 between 1 and 4096 bytes.');
        }
        ($this->messageSink)($message);
    }

    public function hasPermission(string $permission): bool
    {
        return ($this->permissionResolver)($permission);
    }

    public function player(): Player
    {
        return $this->player;
    }
}
