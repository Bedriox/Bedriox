<?php

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
        if ($this->file !== null) {
            try {
                $this->file->write($plain);
            } catch (Throwable) {
                if (!$this->fileFailureReported) {
                    $this->fileFailureReported = true;
                    try {
                        ($this->console)($this->formatter->format(new LogRecord(new DateTimeImmutable(), ++$this->sequence, LogLevel::WARNING, 'File logging is unavailable; continuing with console logging.')) . PHP_EOL);
                    } catch (Throwable) {
                    }
                }
            }
        }
    }

    /** @return list<string> */
    public function recentLines(): array
    {
        return $this->history;
    }
}
