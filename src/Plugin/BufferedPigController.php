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
use Bedriox\Api\Entity\Controller\PigController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Server\Entity\Vanilla\PigEntity;

final class BufferedPigController extends BufferedBreedableAnimalController implements PigController
{
    public function __construct(?PluginActionBuffer $actions, private readonly PigEntity $pig, ?EntityEquipment $equipment = null, ?MobController $delegate = null)
    {
        parent::__construct($actions, $pig, $equipment, $delegate);
    }
    public function setSaddled(bool $saddled): void
    {
        $this->stage(function () use ($saddled): void {
            if (!$this->pig->isRemoved()) {
                $this->pig->setSaddled($saddled);
            }
        });
    }
}
