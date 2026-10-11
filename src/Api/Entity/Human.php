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

namespace Bedriox\Api\Entity;

use Bedriox\Api\Entity\Controller\HumanController;
use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\World\Position;

/** A player-shaped entity without a client session or authenticated player identity. */
interface Human extends LivingEntity
{
    public function getName(): string;

    public function getSkin(): Skin;

    public function setSkin(Skin $skin): void;

    public function teleport(Position $position, ?string $worldName = null): void;

    public function setMotion(Motion $motion): void;

    public function setRotation(float $yaw, float $pitch): void;

    public function setNameTag(string $nameTag): void;

    public function setNameTagVisible(bool $visible): void;

    public function setImmobile(bool $immobile): void;

    public function setInvisible(bool $invisible): void;

    public function setScale(float $scale): void;

    public function setGravityEnabled(bool $enabled): void;

    /** Publishes one ordinary arm swing to the human's admitted viewers. */
    public function swingArm(): void;

    /** Publishes one validated Bedrock emote to the human's admitted viewers. */
    public function playEmote(EmoteId $emote): void;

    public function moveToward(Position $target, float $speed): void;

    public function navigateTo(Position $target, float $speed): void;

    public function cancelNavigation(): void;

    public function lookAt(Position $target): void;

    public function jump(): void;

    public function stopMoving(): void;

    public function getController(): HumanController;
}
