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

namespace Bedriox\Server\Entity\Vanilla\Misc;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;

/** Authoritative fence anchor for one or more leashed animals. */
final class LeashKnotEntity extends AbstractMobEntity
{
    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position)
    {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::leashKnot(),
            $worldName,
            self::anchorPosition(self::blockPosition($position)),
            new AiBehaviorDefinition(),
            new EntityMotion(),
        );
        $this->setAiEnabled(false);
        $this->setGravityEnabled(false);
        $this->setImmobile(true);
    }

    public function fencePosition(): BlockPosition
    {
        return self::blockPosition($this->internalPosition());
    }

    public static function anchorPosition(BlockPosition $fence): Position
    {
        return new Position($fence->x + 0.5, $fence->y + 0.5, $fence->z + 0.5);
    }

    private static function blockPosition(Position $position): BlockPosition
    {
        return new BlockPosition(
            (int) floor($position->x),
            (int) floor($position->y),
            (int) floor($position->z),
        );
    }
}
