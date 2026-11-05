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

namespace Bedriox\Server\Plugin;

use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\Profiling\PerformanceProfiler;
use LogicException;
use Throwable;

final class PluginExecutionContext
{
    /** @var list<PluginExecutionFrame> */
    private array $stack = [];

    public function __construct(
        private readonly int $maxDepth = 32,
        private readonly ?CrashContextPublisher $crashContext = null,
        private readonly ?PerformanceProfiler $profiler = null,
    ) {
        if ($maxDepth < 1 || $maxDepth > 256) {
            throw new LogicException('Plugin execution context depth must be between 1 and 256.');
        }
    }

    public function enter(PluginExecutionFrame $frame): void
    {
        if (count($this->stack) >= $this->maxDepth) {
            throw new PluginException('Plugin execution context depth exceeded.');
        }
        $this->stack[] = $frame;
        $this->publish();
    }

    public function leave(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            throw new LogicException('Plugin execution context stack is empty.');
        }
        $endedAt = hrtime(true);
        if ($frame->startedAtNanoseconds > 0 && is_int($endedAt) && $endedAt >= $frame->startedAtNanoseconds) {
            $name = $frame->event ?? $frame->listener ?? $frame->operation;
            $this->profiler?->record(
                $frame->event === null ? 'plugin' : 'event',
                $name,
                $frame->plugin,
                $endedAt - $frame->startedAtNanoseconds,
                $endedAt - $frame->startedAtNanoseconds,
            );
        }
        $this->publish();
    }

    public function current(): ?PluginExecutionFrame
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[count($this->stack) - 1];
    }

    /** @return list<PluginExecutionFrame> */
    public function snapshot(): array
    {
        return $this->stack;
    }

    private function publish(): void
    {
        if ($this->crashContext === null) {
            return;
        }
        $frame = $this->current();
        $attribution = $frame === null ? null : sprintf(
            '%s %s; operation=%s%s%s%s',
            $frame->plugin,
            $frame->version,
            $frame->operation,
            $frame->event === null ? '' : '; event=' . $frame->event,
            $frame->listener === null ? '' : '; listener=' . $frame->listener,
            $frame->priority === null ? '' : '; priority=' . $frame->priority->name,
        );
        if ($attribution !== null && strlen($attribution) > 512) {
            $attribution = substr($attribution, 0, 512);
        }
        try {
            $this->crashContext->publishPlugin($attribution);
        } catch (Throwable) {
            // Crash attribution must not change plugin execution behavior.
        }
    }
}
