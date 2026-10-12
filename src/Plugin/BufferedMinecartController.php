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

use Bedriox\Api\Entity\Controller\MinecartController;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Server\Entity\Vehicle\Minecart\AbstractMinecartEntity;
use InvalidArgumentException;

final class BufferedMinecartController extends BufferedMobController implements MinecartController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        private readonly AbstractMinecartEntity $minecart,
        ?EntityEquipment $equipment = null,
        ?MobController $delegate = null,
    ) {
        parent::__construct($actions, $minecart, $equipment, $delegate);
    }

    public function setTrackSpeed(float $blocksPerTick): void
    {
        if (!is_finite($blocksPerTick) || abs($blocksPerTick) > AbstractMinecartEntity::MAXIMUM_TRACK_SPEED) {
            throw new InvalidArgumentException('Minecart track speed must be finite and within its supported bounds.');
        }
        $this->stage(function () use ($blocksPerTick): void {
            if (!$this->minecart->isRemoved()) {
                $this->minecart->setTrackSpeed($blocksPerTick);
            }
        });
    }

    public function stop(): void
    {
        $this->stage(function (): void {
            if (!$this->minecart->isRemoved()) {
                $this->minecart->stopTrackMotion();
            }
        });
    }

    public function reverse(): void
    {
        $this->stage(function (): void {
            if (!$this->minecart->isRemoved()) {
                $this->minecart->reverseTrackMotion();
            }
        });
    }
}
