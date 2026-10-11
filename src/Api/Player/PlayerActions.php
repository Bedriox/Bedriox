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

namespace Bedriox\Api\Player;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\World\Position;
use Closure;
use LogicException;

/** @internal Server-owned authoritative action path attached to a live player snapshot. */
final readonly class PlayerActions
{
    /**
     * @param Closure(Position): void            $teleport
     * @param Closure(GameMode): void            $setGameMode
     * @param Closure(float): void             $damage
     * @param Closure(): ?Entity               $vehicle
     * @param Closure(Entity, MountSeat): void $mount
     * @param Closure(): void                  $dismount
     */
    public function __construct(
        private Closure $teleport,
        private Closure $setGameMode,
        private Closure $damage,
        private ?Closure $vehicle = null,
        private ?Closure $mount = null,
        private ?Closure $dismount = null,
        private ?Closure $setMotion = null,
        private ?Closure $playEmote = null,
        private ?Closure $setRotation = null,
        private ?Closure $setNameTag = null,
        private ?Closure $setNameTagVisible = null,
        private ?Closure $setImmobile = null,
        private ?Closure $setInvisible = null,
        private ?Closure $setScale = null,
        private ?Closure $setGravityEnabled = null,
    ) {}

    public static function unavailable(): self
    {
        $unavailable = static function (): never {
            throw new LogicException('This player snapshot is not attached to an authoritative runtime.');
        };

        return new self($unavailable, $unavailable, $unavailable, $unavailable, $unavailable, $unavailable);
    }

    public function teleport(Position $position): void
    {
        ($this->teleport)($position);
    }

    public function setGameMode(GameMode $gameMode): void
    {
        ($this->setGameMode)($gameMode);
    }

    public function damage(float $amount): void
    {
        ($this->damage)($amount);
    }

    public function vehicle(): ?Entity
    {
        return $this->vehicle === null ? null : ($this->vehicle)();
    }

    public function mount(Entity $vehicle, MountSeat $seat): void
    {
        ($this->mount ?? self::unavailableAction())($vehicle, $seat);
    }

    public function dismount(): void
    {
        ($this->dismount ?? self::unavailableAction())();
    }

    public function setMotion(Motion $motion): void
    {
        ($this->setMotion ?? self::unavailableAction())($motion);
    }

    public function playEmote(EmoteId $emote): void
    {
        ($this->playEmote ?? self::unavailableAction())($emote);
    }

    public function setRotation(float $yaw, float $pitch): void
    {
        ($this->setRotation ?? self::unavailableAction())($yaw, $pitch);
    }

    public function setNameTag(string $nameTag): void
    {
        ($this->setNameTag ?? self::unavailableAction())($nameTag);
    }

    public function setNameTagVisible(bool $visible): void
    {
        ($this->setNameTagVisible ?? self::unavailableAction())($visible);
    }

    public function setImmobile(bool $immobile): void
    {
        ($this->setImmobile ?? self::unavailableAction())($immobile);
    }

    public function setInvisible(bool $invisible): void
    {
        ($this->setInvisible ?? self::unavailableAction())($invisible);
    }

    public function setScale(float $scale): void
    {
        ($this->setScale ?? self::unavailableAction())($scale);
    }

    public function setGravityEnabled(bool $enabled): void
    {
        ($this->setGravityEnabled ?? self::unavailableAction())($enabled);
    }

    private static function unavailableAction(): Closure
    {
        return static function (): never {
            throw new LogicException('This player snapshot is not attached to an authoritative runtime.');
        };
    }
}
