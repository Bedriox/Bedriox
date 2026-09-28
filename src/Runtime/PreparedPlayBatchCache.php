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

namespace Bedriox\Server\Runtime;

use Closure;

/** @internal Bounded boot-scoped cache of encoded, unencrypted Bedrock packet batches. */
final class PreparedPlayBatchCache
{
    /** @var array<string, array{batch: string, last_used: int}> */
    private array $entries = [];
    private int $bytes = 0;
    private int $usageSequence = 0;

    public function __construct(
        private readonly int $maximumEntries = 64,
        private readonly int $maximumBytes = 33_554_432,
    ) {
        if ($maximumEntries < 1 || $maximumEntries > 4_096
            || $maximumBytes < 1 || $maximumBytes > 134_217_728) {
            throw new \InvalidArgumentException('Prepared play batch cache limits are invalid.');
        }
    }

    /** @param Closure(): string $encoder */
    public function getOrEncode(string $key, Closure $encoder): string
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null) {
            $this->entries[$key]['last_used'] = ++$this->usageSequence;

            return $entry['batch'];
        }
        $batch = $encoder();
        if ($batch === '') {
            throw new \UnexpectedValueException('Prepared play batch encoder returned an empty batch.');
        }
        if (strlen($batch) <= $this->maximumBytes) {
            $this->entries[$key] = ['batch' => $batch, 'last_used' => ++$this->usageSequence];
            $this->bytes += strlen($batch);
            $this->trim();
        }

        return $batch;
    }

    private function trim(): void
    {
        while (count($this->entries) > $this->maximumEntries || $this->bytes > $this->maximumBytes) {
            $oldestKey = null;
            $oldestUsage = PHP_INT_MAX;
            foreach ($this->entries as $key => $entry) {
                if ($entry['last_used'] < $oldestUsage) {
                    $oldestKey = $key;
                    $oldestUsage = $entry['last_used'];
                }
            }
            if ($oldestKey === null) {
                return;
            }
            $this->bytes -= strlen($this->entries[$oldestKey]['batch']);
            unset($this->entries[$oldestKey]);
        }
    }
}
