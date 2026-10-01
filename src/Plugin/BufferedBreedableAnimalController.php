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

use Bedriox\Api\Entity\Controller\BreedableAnimalController;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use InvalidArgumentException;

class BufferedBreedableAnimalController extends BufferedMobController implements BreedableAnimalController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        protected readonly BreedableAnimalEntity $animal,
        ?EntityEquipment $equipment = null,
        ?MobController $delegate = null,
    ) {
        parent::__construct($actions, $animal, $equipment, $delegate);
    }

    public function setBaby(bool $baby): void
    {
        $this->stage(function () use ($baby): void {
            if (!$this->animal->isRemoved()) {
                $this->animal->setBaby($baby);
            }
        });
    }

    public function setLoveTicks(int $ticks): void
    {
        if ($ticks < 0 || $ticks > BreedableAnimalEntity::MAXIMUM_LOVE_TICKS) {
            throw new InvalidArgumentException('Animal love state is outside its supported bounds.');
        }
        $this->stage(function () use ($ticks): void {
            if (!$this->animal->isRemoved()) {
                $this->animal->setLoveTicks($ticks);
            }
        });
    }
}
