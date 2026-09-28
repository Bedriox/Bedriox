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

final readonly class ChunkStreamingSnapshot
{
    public function __construct(
        public int $sessions,
        public int $visiblePending,
        public int $prefetchPending,
        public int $generationQueued,
        public int $deliveryQueued,
        public int $retained,
        public int $outgoingQueued,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->sessions + $other->sessions,
            $this->visiblePending + $other->visiblePending,
            $this->prefetchPending + $other->prefetchPending,
            $this->generationQueued + $other->generationQueued,
            $this->deliveryQueued + $other->deliveryQueued,
            $this->retained + $other->retained,
            $this->outgoingQueued + $other->outgoingQueued,
        );
    }
}
