<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker;

use InvalidArgumentException;

final class WorkerCoreCount
{
    public static function parse(string $value, ?int $logicalProcessors = null): int
    {
        if ($value === 'auto') {
            $logicalProcessors ??= self::detectLogicalProcessors();

            return $logicalProcessors === null ? 1 : max(1, min(8, $logicalProcessors - 1));
        }
        if (!preg_match('/^(?:0|[1-9][0-9]?)$/D', $value)) {
            throw new InvalidArgumentException('workers.core-count must be auto or an integer from 0 through 32.');
        }
        $count = (int) $value;
        if ($count > 32) {
            throw new InvalidArgumentException('workers.core-count must be auto or an integer from 0 through 32.');
        }

        return $count;
    }

    private static function detectLogicalProcessors(): ?int
    {
        foreach (['NUMBER_OF_PROCESSORS', 'BEDRIOX_LOGICAL_PROCESSORS'] as $name) {
            $value = getenv($name);
            if (is_string($value) && preg_match('/^[1-9][0-9]{0,2}$/D', $value)) {
                return min(256, (int) $value);
            }
        }

        return null;
    }
}
