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
use Bedriox\Api\Entity\Controller\MountController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Player\Player;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Mount\HorseFamilyEntity;
use Bedriox\Server\Entity\Mount\UndeadHorseEntity;
use InvalidArgumentException;

final class BufferedMountController extends BufferedMobController implements MountController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        AbstractMobEntity $entity,
        private readonly HorseFamilyEntity|UndeadHorseEntity $mount,
        ?EntityEquipment $equipment = null,
        ?MobController $delegate = null,
    ) {
        parent::__construct($actions, $entity, $equipment, $delegate);
    }

    public function setOwner(?Player $owner): void
    {
        $ownerUniqueId = $owner?->uuid;
        $this->stage(function () use ($ownerUniqueId): void {
            if (!$this->mount->isRemoved()) {
                $this->mount->setOwnerUniqueId($ownerUniqueId);
            }
        });
    }

    public function setSitting(bool $sitting): void
    {
        $this->stage(function () use ($sitting): void {
            if (!$this->mount->isRemoved()) {
                $this->mount->setSitting($sitting);
            }
        });
    }

    public function setSaddled(bool $saddled): void
    {
        $this->stage(function () use ($saddled): void {
            if (!$this->mount->isRemoved()) {
                $this->mount->setSaddled($saddled);
            }
        });
    }

    public function setTemper(int $temper): void
    {
        if ($temper < 0 || $temper > HorseFamilyEntity::MAXIMUM_TEMPER) {
            throw new InvalidArgumentException('Mount temper is outside its supported bounds.');
        }
        $this->stage(function () use ($temper): void {
            if (!$this->mount->isRemoved()) {
                $this->mount->setTemper($temper);
            }
        });
    }
}
