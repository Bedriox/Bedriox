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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Server\Entity\AbstractLivingEntity;
use InvalidArgumentException;

/** Internal projection emitted after authoritative living-entity equipment changes. */
final readonly class EntityActorEquipmentChanged implements WorldEvent
{
    /**
     * @param list<EquipmentSlot> $changedSlots
     * @param list<string> $recipientSessionIds
     */
    public function __construct(
        public AbstractLivingEntity $entity,
        public int $tick,
        public array $changedSlots,
        public array $recipientSessionIds,
    ) {
        if ($tick < 0 || $changedSlots === []) {
            throw new InvalidArgumentException('Entity equipment projection state is invalid.');
        }
        $seen = [];
        foreach ($changedSlots as $slot) {
            if (isset($seen[$slot->value])) {
                throw new InvalidArgumentException('Entity equipment projection slots are invalid or duplicated.');
            }
            $seen[$slot->value] = true;
        }
    }

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
