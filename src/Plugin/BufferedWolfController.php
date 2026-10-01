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
use Bedriox\Api\Entity\Controller\WolfController;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Player\Player;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use InvalidArgumentException;

final class BufferedWolfController extends BufferedTameableAnimalController implements WolfController
{
    public function __construct(
        ?PluginActionBuffer $actions,
        private readonly WolfEntity $wolf,
        ?EntityEquipment $equipment = null,
        ?MobController $delegate = null,
    ) {
        parent::__construct($actions, $wolf, $equipment, $delegate);
    }

    public function setAngerTarget(Entity|Player|null $target, int $ticks): void
    {
        if ($ticks < 0 || $ticks > MutableAngerState::MAXIMUM_ANGER_TICKS
            || (($target === null) !== ($ticks === 0))) {
            throw new InvalidArgumentException('Anger target and duration are inconsistent or outside bounds.');
        }
        $targetUniqueId = $target === null
            ? null
            : EntityUuid::validate($target instanceof Player ? $target->uuid : $target->getUniqueId());
        $this->stage(function () use ($targetUniqueId, $ticks): void {
            if (!$this->wolf->isRemoved()) {
                $this->wolf->setAngerTargetUniqueId($targetUniqueId, $ticks);
            }
        });
    }
}
