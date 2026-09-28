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

/**
 * Adapter implemented by the managed plugin-worker runtime.
 *
 * @internal
 */
interface PluginAsyncTaskExecutor
{
    public function submit(AsyncTaskRequest $request): void;

    public function cancel(int $taskId): void;

    /** @return list<AsyncTaskOutcome> */
    public function poll(int $maximum): array;

    public function shutdown(): void;
}
