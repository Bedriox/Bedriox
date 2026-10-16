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

namespace Bedriox\Server\World\BlockEntity;

use Bedriox\Api\World\Spawner\TrialSpawnerConfiguration;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Spawner\TrialSpawnerState;

/** Restart-safe authoritative state for one trial-spawner encounter. */
final readonly class TrialSpawnerBlockEntity extends BlockEntity
{
    public function __construct(
        BlockPosition $position,
        public TrialSpawnerState $state,
    ) {
        parent::__construct(BlockEntityType::TrialSpawner, $position, $state->revision);
    }

    public static function create(BlockPosition $position, string $entityIdentifier = 'minecraft:zombie'): self
    {
        return new self($position, TrialSpawnerState::initial(new TrialSpawnerConfiguration($entityIdentifier)));
    }

    public function withState(TrialSpawnerState $state): self
    {
        return $state === $this->state ? $this : new self($this->position, $state);
    }
}
