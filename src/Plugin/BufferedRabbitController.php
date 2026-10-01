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
use Bedriox\Api\Entity\Controller\RabbitController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Entity\Value\RabbitVariant;
use Bedriox\Server\Entity\Vanilla\RabbitEntity;

final class BufferedRabbitController extends BufferedBreedableAnimalController implements RabbitController
{
    public function __construct(?PluginActionBuffer $actions, private readonly RabbitEntity $rabbit, ?EntityEquipment $equipment = null, ?MobController $delegate = null)
    {
        parent::__construct($actions, $rabbit, $equipment, $delegate);
    }
    public function setVariant(RabbitVariant $variant): void
    {
        $this->stage(function () use ($variant): void {
            if (!$this->rabbit->isRemoved()) {
                $this->rabbit->setVariant($variant);
            }
        });
    }
}
