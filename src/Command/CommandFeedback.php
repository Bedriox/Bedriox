<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Command;

use Bedriox\Api\Command\CommandResultType;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\TextFormat;

/** Applies consistent Bedrock formatting without leaking formatting codes into console output. */
final class CommandFeedback
{
    public static function result(CommandSender $sender, CommandResultType $type, string $message): string
    {
        return match ($type) {
            CommandResultType::SUCCESS => self::success($sender, $message),
            CommandResultType::INFORMATION => self::normal($sender, $message),
            CommandResultType::WARNING => self::warning($sender, $message),
            CommandResultType::FAILURE => self::error($sender, $message),
        };
    }

    public static function normal(CommandSender $sender, string $message): string
    {
        return self::line($sender, TextFormat::GRAY, $message);
    }

    public static function success(CommandSender $sender, string $message): string
    {
        return self::line($sender, TextFormat::GREEN, $message);
    }

    public static function warning(CommandSender $sender, string $message): string
    {
        return self::line($sender, TextFormat::YELLOW, $message);
    }

    public static function error(CommandSender $sender, string $message): string
    {
        return self::line($sender, TextFormat::RED, $message);
    }

    public static function header(CommandSender $sender, string $message): string
    {
        return self::line($sender, TextFormat::GOLD, $message);
    }

    public static function formatted(CommandSender $sender, string $playerMessage, string $consoleMessage): string
    {
        return $sender->type() === CommandSenderType::PLAYER
            ? $playerMessage . TextFormat::RESET
            : $consoleMessage;
    }

    public static function line(CommandSender $sender, string $color, string $message): string
    {
        return $sender->type() === CommandSenderType::PLAYER
            ? $color . $message . TextFormat::RESET
            : $message;
    }

    private function __construct() {}
}
