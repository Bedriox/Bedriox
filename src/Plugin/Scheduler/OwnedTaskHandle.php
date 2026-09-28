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

use Bedriox\Api\Scheduler\TaskHandle;
use Bedriox\Api\Scheduler\TaskState;
use Closure;

/** @internal */
final class OwnedTaskHandle implements TaskHandle
{
    /** @param Closure(int): void $canceller */
    public function __construct(
        private readonly int $taskId,
        private readonly Closure $canceller,
        private TaskState $taskState = TaskState::QUEUED,
    ) {}

    public function id(): int
    {
        return $this->taskId;
    }

    public function state(): TaskState
    {
        return $this->taskState;
    }

    public function cancel(): void
    {
        if (!$this->taskState->isTerminal()) {
            ($this->canceller)($this->taskId);
        }
    }

    public function transition(TaskState $state): void
    {
        if ($this->taskState->isTerminal()) {
            return;
        }
        $this->taskState = $state;
    }
}
