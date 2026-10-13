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

namespace Bedriox\Server\World\Generation\Structure;

use Closure;
use InvalidArgumentException;

/** Worker-local bounded least-recently-used cache for immutable structure plans. */
final class StructurePlanCache
{
    /** @var array<string, StructurePlan|null> */
    private array $plans = [];

    /** @var array<string, int> */
    private array $access = [];

    private int $clock = 0;

    public function __construct(private readonly int $capacity = 256)
    {
        if ($capacity < 1 || $capacity > 8_192) {
            throw new InvalidArgumentException('Structure plan-cache capacity must be between 1 and 8192.');
        }
    }

    /** @param Closure(): (?StructurePlan) $compute */
    public function getOrCompute(string $key, Closure $compute): ?StructurePlan
    {
        if (array_key_exists($key, $this->plans)) {
            $this->access[$key] = ++$this->clock;
            return $this->plans[$key];
        }
        $plan = $compute();
        if (count($this->plans) >= $this->capacity) {
            asort($this->access, SORT_NUMERIC);
            $oldest = array_key_first($this->access);
            if (is_string($oldest)) {
                unset($this->access[$oldest], $this->plans[$oldest]);
            }
        }
        $this->plans[$key] = $plan;
        $this->access[$key] = ++$this->clock;

        return $plan;
    }

    public function count(): int
    {
        return count($this->plans);
    }
}
