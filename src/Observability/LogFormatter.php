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

namespace Bedriox\Server\Observability;

final class LogFormatter
{
    private const string RESET = "\033[0m";

    public function format(LogRecord $record, bool $colors = false): string
    {
        $timestamp = '[' . $record->timestamp->format('d-M-Y H:i:s') . ']';
        $level = $record->level->name;
        $source = $record->component === null ? '' : '[' . $record->component . '] ';
        if (!$colors) {
            return sprintf('%s Bedriox %s > %s%s', $timestamp, $level, $source, $record->message);
        }

        $levelColor = match ($record->level) {
            LogLevel::DEBUG => "\033[90m",
            LogLevel::INFO => "\033[32m",
            LogLevel::NOTICE => "\033[36m",
            LogLevel::WARNING => "\033[33m",
            LogLevel::ERROR => "\033[91m",
            LogLevel::CRITICAL => "\033[97;41m",
        };

        return sprintf(
            "\033[90m%s%s \033[36mBedriox%s %s%s%s > %s%s",
            $timestamp,
            self::RESET,
            self::RESET,
            $levelColor,
            $level,
            self::RESET,
            $source,
            $record->message,
        );
    }
}
