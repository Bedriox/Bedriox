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

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\Controller\TameableAnimalController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Player\Player;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\TameableAnimalEntity;

class BufferedTameableAnimalController extends BufferedBreedableAnimalController implements TameableAnimalController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        private readonly TameableAnimalEntity $tameable,
        ?EntityEquipment $equipment = null,
        ?MobController $delegate = null,
    ) {
        parent::__construct($actions, $tameable, $equipment, $delegate);
    }

    public function setOwner(?Player $owner): void
    {
        $ownerUniqueId = $owner === null ? null : EntityUuid::validate($owner->uuid);
        $this->stage(function () use ($ownerUniqueId): void {
            if (!$this->tameable->isRemoved()) {
                $this->tameable->setOwnerUniqueId($ownerUniqueId);
            }
        });
    }

    public function setSitting(bool $sitting): void
    {
        $this->stage(function () use ($sitting): void {
            if (!$this->tameable->isRemoved()) {
                $this->tameable->setSitting($sitting);
            }
        });
    }

}
