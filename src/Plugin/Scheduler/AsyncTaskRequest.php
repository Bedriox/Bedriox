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

namespace Bedriox\Server\Plugin\Scheduler;

use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Server\Plugin\PluginArchiveIdentity;

/** @internal */
final readonly class AsyncTaskRequest
{
    /** @param non-empty-string $taskClass */
    public function __construct(
        public int $taskId,
        public string $owner,
        public string $ownerVersion,
        public int $ownerGeneration,
        public PluginArchiveIdentity $packageIdentity,
        public string $taskClass,
        public AsyncTaskValue $input,
        public int $deadlineNanoseconds,
    ) {}
}
