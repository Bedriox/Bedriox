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

use Bedriox\Api\Plugin\PluginContext;

/**
 * Process-isolated work with main-thread lifecycle callbacks.
 *
 * The worker constructs a fresh instance and invokes only onRun(); instance
 * mutations made there are not transferred back to the main process.
 */
abstract class AsyncTask
{
    abstract public function onRun(AsyncTaskValue $input): AsyncTaskValue;

    public function onCompletion(AsyncTaskValue $result, PluginContext $context): void {}

    public function onFailure(AsyncTaskFailure $failure, PluginContext $context): void {}
}
