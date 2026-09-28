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

use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Scheduler\AsyncTask;
use Bedriox\Api\Scheduler\AsyncTaskValue;
use Bedriox\Api\Scheduler\PluginScheduler;
use Bedriox\Api\Scheduler\TaskHandle;
use Bedriox\Api\Scheduler\TaskRejectedException;
use Bedriox\Server\Plugin\PluginArchiveIdentity;
use LogicException;

/** @internal */
final class OwnedPluginScheduler implements PluginScheduler
{
    private ?PluginContext $context = null;

    public function __construct(
        private MainThreadPluginScheduler $scheduler,
        private string $owner,
        private string $ownerVersion,
        private ?PluginArchiveIdentity $packageIdentity,
        private int $ownerGeneration,
    ) {}

    public function nextTick(callable $callback): TaskHandle
    {
        return $this->scheduler->schedule($this->owner, 1, 0, $callback);
    }

    public function delayed(int $delayTicks, callable $callback): TaskHandle
    {
        return $this->scheduler->schedule($this->owner, $delayTicks, 0, $callback);
    }

    public function repeating(int $periodTicks, callable $callback): TaskHandle
    {
        return $this->scheduler->schedule($this->owner, $periodTicks, $periodTicks, $callback);
    }

    public function delayedRepeating(int $delayTicks, int $periodTicks, callable $callback): TaskHandle
    {
        return $this->scheduler->schedule($this->owner, $delayTicks, $periodTicks, $callback);
    }

    public function async(AsyncTask $task, AsyncTaskValue $input): TaskHandle
    {
        if (!$this->context instanceof PluginContext) {
            throw new TaskRejectedException('The plugin scheduler context is not attached.');
        }

        return $this->scheduler->submitAsync(
            $this->owner,
            $this->ownerVersion,
            $this->packageIdentity,
            $this->ownerGeneration,
            $task,
            $input,
            $this->context,
        );
    }

    /** @internal Attaches the final owner context before plugin lifecycle begins. */
    public function attachContext(PluginContext $context): void
    {
        if ($this->context !== null) {
            throw new LogicException('The plugin scheduler context is already attached.');
        }
        if (strcasecmp($context->name(), $this->owner) !== 0) {
            throw new LogicException('The plugin scheduler context owner does not match.');
        }
        $this->context = $context;
    }
}
