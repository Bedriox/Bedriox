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

use Closure;
use DateTimeImmutable;
use Throwable;

final class ServerLogger
{
    private const int MAXIMUM_HISTORY = 200;
    private int $sequence = 0;
    /** @var list<string> */
    private array $history = [];
    private bool $fileFailureReported = false;

    /** @param Closure(string): void $console */
    public function __construct(
        private readonly Closure $console,
        private readonly LogLevel $minimumLevel,
        private readonly bool $consoleEnabled,
        private readonly bool $colors,
        private readonly ?RotatingFileLog $file,
        private readonly LogFormatter $formatter = new LogFormatter(),
        private readonly LogRedactor $redactor = new LogRedactor(),
        private readonly ?BackgroundLogWriter $backgroundFile = null,
    ) {}

    public function debug(string $message, ?string $component = null): void
    {
        $this->log(LogLevel::DEBUG, $message, $component);
    }
    public function info(string $message, ?string $component = null): void
    {
        $this->log(LogLevel::INFO, $message, $component);
    }
    public function notice(string $message, ?string $component = null): void
    {
        $this->log(LogLevel::NOTICE, $message, $component);
    }
    public function warning(string $message, ?string $component = null): void
    {
        $this->log(LogLevel::WARNING, $message, $component);
    }
    public function error(string $message, ?string $component = null): void
    {
        $this->log(LogLevel::ERROR, $message, $component);
    }
    public function critical(string $message, ?string $component = null): void
    {
        $this->log(LogLevel::CRITICAL, $message, $component);
    }

    public function log(LogLevel $level, string $message, ?string $component = null): void
    {
        if ($level->value < $this->minimumLevel->value) {
            return;
        }
        $record = new LogRecord(new DateTimeImmutable(), ++$this->sequence, $level, $this->redactor->redact($message), $component);
        $plain = $this->formatter->format($record);
        $this->history[] = $plain;
        if (count($this->history) > self::MAXIMUM_HISTORY) {
            array_shift($this->history);
        }
        if ($this->consoleEnabled) {
            try {
                ($this->console)($this->formatter->format($record, $this->colors) . PHP_EOL);
            } catch (Throwable) {
                // Logging must not change runtime behavior.
            }
        }
        if ($this->backgroundFile !== null) {
            try {
                if ($this->backgroundFile->enqueue($record->sequence, $level, $plain) === LogQueueSubmission::DROPPED) {
                    $this->reportFileFailure('The background log queue is full; some file log records were dropped.');
                }
            } catch (Throwable) {
                $this->reportFileFailure('Background file logging is unavailable; continuing with console logging.');
            }
        } elseif ($this->file !== null) {
            try {
                $this->file->write($plain);
            } catch (Throwable) {
                $this->reportFileFailure('File logging is unavailable; continuing with console logging.');
            }
        }
    }

    /** @return list<string> */
    public function recentLines(): array
    {
        return $this->history;
    }

    private function reportFileFailure(string $message): void
    {
        if ($this->fileFailureReported) {
            return;
        }
        $this->fileFailureReported = true;
        try {
            ($this->console)($this->formatter->format(new LogRecord(
                new DateTimeImmutable(),
                ++$this->sequence,
                LogLevel::WARNING,
                $message,
            )) . PHP_EOL);
        } catch (Throwable) {
        }
    }
}
