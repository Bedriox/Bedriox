<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\TextFormat;

/** Applies Bedrock formatting only to player-facing command output. */
final class CommandMessageStyle
{
    public static function line(CommandSender $sender, string $color, string $message): string
    {
        return $sender->type() === CommandSenderType::PLAYER
            ? $color . $message . TextFormat::RESET
            : $message;
    }

    private function __construct() {}
}
