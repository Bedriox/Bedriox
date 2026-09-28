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

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\World\ChunkPosition;

final readonly class EntityPersistenceFlushResult
{
    /** @var list<ChunkPosition> */
    private array $failedChunks;

    /** @var list<array{chunk: ChunkPosition, operation: string, exception: string, detail: string}> */
    private array $failureDetails;

    /**
     * @param array<int, ChunkPosition> $failedChunks
     * @param array<int, array{chunk: ChunkPosition, operation: string, exception: string, detail: string}> $failureDetails
     */
    public function __construct(
        public int $attemptedChunks,
        public int $savedChunks,
        array $failedChunks,
        array $failureDetails = [],
    ) {
        $this->failedChunks = array_values($failedChunks);
        $this->failureDetails = array_values($failureDetails);
    }

    /** @return list<ChunkPosition> */
    public function failedChunks(): array
    {
        return $this->failedChunks;
    }

    public function failedChunksCount(): int
    {
        return count($this->failedChunks);
    }

    /** @return list<array{chunk: ChunkPosition, operation: string, exception: string, detail: string}> */
    public function failureDetails(): array
    {
        return $this->failureDetails;
    }
}
