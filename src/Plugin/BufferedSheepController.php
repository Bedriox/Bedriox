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

use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Entity\MobController;
use Bedriox\Api\Entity\SheepController;
use Bedriox\Api\Entity\WoolColor;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use InvalidArgumentException;

/** @internal Collects sheep-specific mutations in the active plugin transaction. */
final class BufferedSheepController extends BufferedMobController implements SheepController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        private readonly SheepEntity $sheep,
        ?EntityEquipment $equipment = null,
        ?MobController $delegate = null,
    ) {
        parent::__construct($actions, $sheep, $equipment, $delegate);
    }

    public function setBaby(bool $baby): void
    {
        $this->stage(function () use ($baby): void {
            if (!$this->sheep->isRemoved()) {
                $this->sheep->setBaby($baby);
            }
        });
    }

    public function setLoveTicks(int $ticks): void
    {
        if ($ticks < 0 || $ticks > SheepEntity::MAXIMUM_LOVE_TICKS) {
            throw new InvalidArgumentException('Sheep love state is outside its supported bounds.');
        }
        $this->stage(function () use ($ticks): void {
            if (!$this->sheep->isRemoved()) {
                $this->sheep->setLoveTicks($ticks);
            }
        });
    }

    public function setSheared(bool $sheared): void
    {
        $this->stage(function () use ($sheared): void {
            if (!$this->sheep->isRemoved()) {
                $this->sheep->setSheared($sheared);
            }
        });
    }

    public function setWoolColor(WoolColor $color): void
    {
        $this->stage(function () use ($color): void {
            if (!$this->sheep->isRemoved()) {
                $this->sheep->setWoolColor($color);
            }
        });
    }
}
