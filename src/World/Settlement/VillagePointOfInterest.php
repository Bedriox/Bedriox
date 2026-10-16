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

namespace Bedriox\Server\World\Settlement;

use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Entity\Value\VillagerWorkstation;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final readonly class VillagePointOfInterest
{
    public function __construct(
        public VillagePointOfInterestType $type,
        public BlockPosition $position,
        public string $blockIdentifier,
        public ?VillagerProfession $profession = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $blockIdentifier) !== 1) {
            throw new InvalidArgumentException('Village point-of-interest block identifier is invalid.');
        }
        $workstation = VillagerWorkstation::tryFrom($blockIdentifier);
        if (($type === VillagePointOfInterestType::WORKSTATION) !== ($workstation !== null)
            || ($workstation?->profession()) !== $profession) {
            throw new InvalidArgumentException('Village point-of-interest profession does not match its block.');
        }
    }

    public function key(): string
    {
        return $this->position->x . ':' . $this->position->y . ':' . $this->position->z;
    }
}
