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

namespace Bedriox\Server\Entity\Spawn\Structure;

use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;

/** World-provider boundary for one-time generated-village population claims. */
final readonly class VillagePopulationStateRepository
{
    private const string NAMESPACE = 'village_population';

    public function __construct(private TransientEntityPersistenceStore $store) {}

    public function load(): VillagePopulationState
    {
        $payload = $this->store->loadTransientEntities(self::NAMESPACE);

        return $payload === null ? new VillagePopulationState() : VillagePopulationState::decode($payload);
    }

    public function save(VillagePopulationState $state): void
    {
        $this->store->saveTransientEntities(self::NAMESPACE, $state->encode());
    }
}
