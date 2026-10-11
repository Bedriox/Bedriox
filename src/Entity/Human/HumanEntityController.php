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

namespace Bedriox\Server\Entity\Human;

use Bedriox\Api\Entity\Controller\HumanController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Entity\Skin;
use Bedriox\Api\World\Position;
use Bedriox\Server\Plugin\BufferedMobController;
use Bedriox\Server\Plugin\PluginActionBuffer;

/** @internal Stages human mutations through the same bounded plugin action path as mobs. */
final class HumanEntityController extends BufferedMobController implements HumanController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        private readonly HumanEntity $human,
        EntityEquipment $equipment,
    ) {
        parent::__construct($actions, $human, $equipment);
    }

    public function setSkin(Skin $skin): void
    {
        $this->stage(function () use ($skin): void {
            if (!$this->human->isRemoved()) {
                $this->human->applySkin($skin);
            }
        });
    }

    protected function resolveNavigationTarget(Position $target): Position
    {
        return $this->human->resolveNavigationTarget($target);
    }
}
