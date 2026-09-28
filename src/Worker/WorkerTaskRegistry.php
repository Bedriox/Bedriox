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

final class WorkerTaskRegistry
{
    /** @var array<int, WorkerTaskDefinition> */
    private array $definitions = [];

    public function register(WorkerTaskDefinition $definition): void
    {
        if (isset($this->definitions[$definition->id])) {
            throw new InvalidArgumentException('Worker task type is already registered.');
        }
        $this->definitions[$definition->id] = $definition;
    }

    public function get(int $id): ?WorkerTaskDefinition
    {
        return $this->definitions[$id] ?? null;
    }

    /** @return list<WorkerTaskDefinition> */
    public function all(): array
    {
        ksort($this->definitions);

        return array_values($this->definitions);
    }

    public function digest(): string
    {
        $parts = [];
        foreach ($this->all() as $definition) {
            $parts[] = implode(':', [
                $definition->id,
                $definition->schemaVersion,
                $definition->owner,
                $definition->lane->value,
                $definition->handlerClass,
                $definition->maximumInputBytes,
                $definition->maximumResultBytes,
                $definition->timeoutMilliseconds,
                (int) $definition->cancellable,
                (int) $definition->retryWhenNotStarted,
            ]);
        }

        return hash('sha256', implode("\n", $parts));
    }
}
