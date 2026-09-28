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

/** @internal Main-thread bookkeeping for one in-flight durable ownership move. */
final readonly class PendingEntityOwnershipTransfer
{
    /**
     * @param array<string, array{revision: int, age: int}> $runtimeBaselines
     */
    public function __construct(
        public EntityOwnershipTransfer $transfer,
        public string $sourceKey,
        public string $destinationKey,
        public int $sourceMutationRevision,
        public int $destinationMutationRevision,
        public int $runtimeRevisionBaseline,
        public int $runtimeAgeBaseline,
        public array $runtimeBaselines,
    ) {}
}
