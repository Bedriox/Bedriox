<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

use InvalidArgumentException;

/** Already formatted and redacted before it crosses the background-writer boundary. */
final readonly class QueuedLogLine
{
    public function __construct(
        public int $sequence,
        public LogLevel $level,
        public string $line,
    ) {
        if ($sequence < 1) {
            throw new InvalidArgumentException('Log sequence must be positive.');
        }
    }

    public function bytes(): int
    {
        return strlen($this->line) + strlen(PHP_EOL);
    }
}
