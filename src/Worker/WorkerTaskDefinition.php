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

final readonly class WorkerTaskDefinition
{
    /** @param class-string<WorkerTaskHandler> $handlerClass */
    public function __construct(
        public int $id,
        public int $schemaVersion,
        public string $owner,
        public WorkerLane $lane,
        public string $handlerClass,
        public int $maximumInputBytes,
        public int $maximumResultBytes,
        public int $timeoutMilliseconds,
        public bool $cancellable = true,
        public bool $retryWhenNotStarted = false,
    ) {
        if ($id < 1 || $id > 65_535 || $schemaVersion < 1 || $schemaVersion > 65_535
            || !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $owner)
            || $maximumInputBytes < 0 || $maximumInputBytes > 16_777_216
            || $maximumResultBytes < 0 || $maximumResultBytes > 33_554_432
            || $timeoutMilliseconds < 1 || $timeoutMilliseconds > 300_000) {
            throw new InvalidArgumentException('Invalid worker task definition.');
        }
    }
}
