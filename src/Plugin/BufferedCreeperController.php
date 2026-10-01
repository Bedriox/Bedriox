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

use Bedriox\Api\Entity\Controller\CreeperController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;

final class BufferedCreeperController extends BufferedMobController implements CreeperController
{
    public function __construct(?PluginActionBuffer $actions, private readonly CreeperEntity $creeper, ?EntityEquipment $equipment = null)
    {
        parent::__construct($actions, $creeper, $equipment);
    }

    public function setCharged(bool $charged): void
    {
        $this->stage(function () use ($charged): void {
            if (!$this->creeper->isRemoved()) {
                $this->creeper->setCharged($charged);
            }
        });
    }

    public function setIgnited(bool $ignited): void
    {
        $this->stage(function () use ($ignited): void {
            if (!$this->creeper->isRemoved()) {
                $this->creeper->setIgnited($ignited);
            }
        });
    }
}
