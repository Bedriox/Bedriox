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

/** Allocation-on-demand view of memory-bearing state retained by one play session. */
final readonly class BedrockPlayChannelResidencySnapshot
{
    public function __construct(
        public int $queuedPayloads,
        public int $queuedBytes,
        public int $commands,
        public int $playerCommands,
        public int $formResponses,
        public int $bootstrapPackets,
        public int $streamingResponses,
        public int $generatedChunks,
        public int $retainedChunks,
        public int $queuedChunks,
        public int $pendingChunkRequests,
        public int $visibilityChanges,
        public int $requestedMaps,
        public int $pendingMaps,
        public int $authorizedMaps,
        public int $scannedMapChunks,
        public int $trackedSubChunkRequests,
        public int $servedSubChunkSections,
        public int $skinChanges,
        public int $traceBuckets,
    ) {}

    public function retainedEntries(): int
    {
        return $this->queuedPayloads
            + $this->commands
            + $this->playerCommands
            + $this->formResponses
            + $this->bootstrapPackets
            + $this->streamingResponses
            + $this->generatedChunks
            + $this->retainedChunks
            + $this->queuedChunks
            + $this->pendingChunkRequests
            + $this->visibilityChanges
            + $this->requestedMaps
            + $this->pendingMaps
            + $this->authorizedMaps
            + $this->scannedMapChunks
            + $this->trackedSubChunkRequests
            + $this->servedSubChunkSections
            + $this->skinChanges
            + $this->traceBuckets;
    }
}
