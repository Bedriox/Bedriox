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

namespace Bedriox\Server\ResourcePack;

/** Bounded process-wide cache which prevents duplicate disk reads during join bursts. */
final class ResourcePackChunkCache
{
    /** @var array<string, array{bytes: string, used: int}> */
    private array $entries = [];
    private int $retainedBytes = 0;
    private int $sequence = 0;

    public function __construct(
        private readonly int $maximumEntries = 64,
        private readonly int $maximumBytes = 16_777_216,
    ) {
        if ($maximumEntries < 1 || $maximumEntries > 4_096
            || $maximumBytes < ResourcePackArchive::CHUNK_BYTES || $maximumBytes > 134_217_728) {
            throw new \InvalidArgumentException('Resource-pack chunk cache limits are invalid.');
        }
    }

    public function read(ResourcePackArchive $pack, int $index): string
    {
        $key = bin2hex($pack->sha256) . ':' . $index;
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null) {
            $this->entries[$key]['used'] = ++$this->sequence;

            return $entry['bytes'];
        }
        $bytes = $pack->readChunk($index);
        $this->entries[$key] = ['bytes' => $bytes, 'used' => ++$this->sequence];
        $this->retainedBytes += strlen($bytes);
        $this->evict();

        return $bytes;
    }

    private function evict(): void
    {
        while (count($this->entries) > $this->maximumEntries || $this->retainedBytes > $this->maximumBytes) {
            $oldestKey = null;
            $oldestUse = PHP_INT_MAX;
            foreach ($this->entries as $key => $entry) {
                if ($entry['used'] < $oldestUse) {
                    $oldestKey = $key;
                    $oldestUse = $entry['used'];
                }
            }
            if ($oldestKey === null) {
                return;
            }
            $this->retainedBytes -= strlen($this->entries[$oldestKey]['bytes']);
            unset($this->entries[$oldestKey]);
        }
    }
}
