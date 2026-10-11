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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\PortalType;
use Bedriox\Api\World\Position;
use InvalidArgumentException;

/** Runs after portal contact has matured and before a dimension transfer is scheduled. */
final class PlayerPortalTravelEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly PortalType $portalType,
        public readonly Position $from,
        private Position $destination,
    ) {
        $this->validateDestination($destination);
    }

    public function destination(): Position
    {
        return $this->destination;
    }

    public function setDestination(Position $destination): void
    {
        $this->assertMutable();
        $this->validateDestination($destination);
        $this->destination = $destination;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->destination];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof Position) {
            throw new InvalidArgumentException('Invalid player portal travel event state.');
        }
        parent::replaceState($state[0]);
        $this->destination = $state[1];
    }

    private function validateDestination(Position $destination): void
    {
        $this->from->validateResolved();
        $destination->validateResolved();
        $sourceDimension = $this->from->world?->getDimensionType()
            ?? throw new InvalidArgumentException('A portal source must contain a resolved world.');
        $expected = match ($this->portalType) {
            PortalType::NETHER => match ($sourceDimension) {
                DimensionType::OVERWORLD => DimensionType::NETHER,
                DimensionType::NETHER => DimensionType::OVERWORLD,
                default => null,
            },
            PortalType::END => match ($sourceDimension) {
                DimensionType::OVERWORLD => DimensionType::END,
                DimensionType::END => DimensionType::OVERWORLD,
                default => null,
            },
        };
        if ($expected === null || $destination->world?->getDimensionType() !== $expected) {
            throw new InvalidArgumentException(
                'A portal destination must select the portal type paired dimension.',
            );
        }
    }
}
