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

namespace Bedriox\Api\Event\Display;

use Bedriox\Api\Display\DisplayInteractionType;
use Bedriox\Api\Display\InteractionEntity;
use Bedriox\Api\Display\TextDisplay;
use Bedriox\Api\Entity\Vector3;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Player\Player;

/** Immutable notification after a display interaction is admitted. */
final class DisplayInteractedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly TextDisplay $display,
        public readonly InteractionEntity $interactionEntity,
        public readonly DisplayInteractionType $interactionType,
        public readonly ?EquipmentSlot $hand = null,
        public readonly ?Vector3 $hitPosition = null,
    ) {}
}
