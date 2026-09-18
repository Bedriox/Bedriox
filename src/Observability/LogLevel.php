<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

use InvalidArgumentException;

enum LogLevel: int
{
    case DEBUG = 10;
    case INFO = 20;
    case NOTICE = 30;
    case WARNING = 40;
    case ERROR = 50;
    case CRITICAL = 60;

    public static function parse(string $value): self
    {
        return self::tryFromName(strtoupper($value))
            ?? throw new InvalidArgumentException('Logging level is unsupported.');
    }

    private static function tryFromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
