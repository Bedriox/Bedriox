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

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;

/** Goal definitions are immutable and shared; per-entity state belongs in memory. */
interface AiGoal
{
    public function identifier(): string;

    public function priority(): int;

    public function evaluationIntervalTicks(): int;

    /** @return list<AiControl> */
    public function controls(): array;

    public function canStart(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool;

    public function shouldContinue(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): bool;

    public function start(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;

    public function tick(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;

    public function stop(AbstractMobEntity $entity, AiMemoryStore $memory, AiTickContext $context): void;
}
