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

namespace Bedriox\Server\Worker;

use InvalidArgumentException;

final readonly class WorkerAllocation
{
    public function __construct(
        public int $logicalProcessors,
        public int $reservedProcessors,
        public int $coreWorkers,
        public int $pluginWorkers,
    ) {}

    public static function resolve(
        string $coreValue,
        string $pluginValue,
        ?int $logicalProcessors = null,
    ): self {
        $logicalProcessors ??= WorkerCoreCount::detectLogicalProcessors() ?? 4;
        if ($logicalProcessors < 1 || $logicalProcessors > 256) {
            throw new InvalidArgumentException('Logical processor count must be from 1 through 256.');
        }

        $core = self::configuredCount($coreValue, 'workers.core-count');
        $plugin = self::configuredCount($pluginValue, 'workers.plugin-count');
        $reserved = min($logicalProcessors - 1, max(2, (int) ceil($logicalProcessors / 4)));
        $available = max(1, $logicalProcessors - $reserved);

        if ($core === null && $plugin === null) {
            $plugin = $logicalProcessors <= 4 ? 1 : min(4, max(1, intdiv($available, 3)));
            $core = min(8, max(1, $available - $plugin));
        } elseif ($core === null) {
            $core = min(8, max(1, $available - $plugin));
        } elseif ($plugin === null) {
            $plugin = min(4, max(1, $available - $core));
        }

        return new self($logicalProcessors, $reserved, $core, $plugin);
    }

    private static function configuredCount(string $value, string $setting): ?int
    {
        if ($value === 'auto') {
            return null;
        }
        if (!preg_match('/^(?:0|[1-9][0-9]?)$/D', $value) || (int) $value > 32) {
            throw new InvalidArgumentException($setting . ' must be auto or an integer from 0 through 32.');
        }

        return (int) $value;
    }
}
