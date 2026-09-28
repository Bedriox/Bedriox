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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use InvalidArgumentException;

final readonly class NaturalSpawnBatch
{
    /** @var list<EntitySpawnRequest> */
    private array $requests;

    /** @param array<int, EntitySpawnRequest> $requests */
    public function __construct(
        array $requests,
        public int $attempts,
        public int $candidateCount,
        public bool $timeBudgetExhausted,
        public bool $attemptBudgetExhausted,
    ) {
        if (!array_is_list($requests) || $attempts < 0 || $candidateCount < 0 || $attempts > $candidateCount) {
            throw new InvalidArgumentException('Natural-spawn batch metrics are invalid.');
        }
        $this->requests = $requests;
    }

    /** @return list<EntitySpawnRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
