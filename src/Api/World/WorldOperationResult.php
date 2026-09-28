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

namespace Bedriox\Api\World;

use InvalidArgumentException;

final readonly class WorldOperationResult
{
    public function __construct(
        public WorldOperationType $type,
        public WorldOperationState $state,
        public string $worldId,
        public ?World $world = null,
        public ?WorldOperationFailure $failure = null,
    ) {
        if (!$state->isTerminal()) {
            throw new InvalidArgumentException('A world operation result must have a terminal state.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $worldId) !== 1) {
            throw new InvalidArgumentException('World operation result ID must be canonical.');
        }
        if ($state === WorldOperationState::SUCCEEDED) {
            if (!$world instanceof World || $failure !== null || $world->id() !== $worldId) {
                throw new InvalidArgumentException('A successful world operation requires its matching world and no failure.');
            }

            return;
        }
        if (!$failure instanceof WorldOperationFailure) {
            throw new InvalidArgumentException('An unsuccessful world operation requires bounded failure information.');
        }
        if ($world !== null && $world->id() !== $worldId) {
            throw new InvalidArgumentException('World operation result handle does not match its world ID.');
        }
    }

    public function succeeded(): bool
    {
        return $this->state === WorldOperationState::SUCCEEDED;
    }
}
