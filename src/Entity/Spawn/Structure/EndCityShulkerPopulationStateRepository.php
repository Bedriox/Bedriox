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

final readonly class EndCityShulkerPopulationStateRepository
{
    private const string NAMESPACE = 'end_city_shulker_population';

    public function __construct(private TransientEntityPersistenceStore $store) {}

    public function load(): EndCityShulkerPopulationState
    {
        $payload = $this->store->loadTransientEntities(self::NAMESPACE);

        return $payload === null ? new EndCityShulkerPopulationState() : EndCityShulkerPopulationState::decode($payload);
    }

    public function save(EndCityShulkerPopulationState $state): void
    {
        $this->store->saveTransientEntities(self::NAMESPACE, $state->encode());
    }
}
