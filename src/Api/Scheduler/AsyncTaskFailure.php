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

namespace Bedriox\Api\Scheduler;

final readonly class AsyncTaskFailure
{
    public function __construct(
        public string $type,
        public string $message,
    ) {
        if ($type === '' || strlen($type) > 256) {
            throw new \InvalidArgumentException('Async task failure type must contain between 1 and 256 bytes.');
        }
        if (strlen($message) > 1024) {
            throw new \InvalidArgumentException('Async task failure message cannot exceed 1024 bytes.');
        }
    }
}
