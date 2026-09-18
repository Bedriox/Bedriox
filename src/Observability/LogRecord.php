<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

use DateTimeImmutable;

final readonly class LogRecord
{
    public function __construct(
        public DateTimeImmutable $timestamp,
        public int $sequence,
        public LogLevel $level,
        public string $message,
        public ?string $component = null,
    ) {}
}
