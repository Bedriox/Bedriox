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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\Vanilla\Goat;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\ExclusiveAiActivity;
use Bedriox\Server\Entity\Concern\IncomingDamageModifier;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Movement\AvoidsPowderSnow;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class GoatEntity extends LandBreedableAnimalEntity implements ExclusiveAiActivity, Goat, IncomingDamageModifier, AvoidsPowderSnow
{
    private const float FALL_DAMAGE_REDUCTION = 10.0;
    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?AiBehaviorDefinition $behavior = null,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        bool $baby = false,
        private bool $screaming = false,
        private bool $leftHorn = true,
        private bool $rightHorn = true,
        private bool $ramming = false,
        private int $ramTicks = 0,
        private int $ramCooldownTicks = 0,
        private ?string $ramTargetUniqueId = null,
        private bool $ramTargetPlayer = true,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::goat(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::passive('goat', ['minecraft:wheat'], 0.12), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        if ($ramTicks < 0 || $ramTicks > 200 || $ramCooldownTicks < 0 || $ramCooldownTicks > 6_000) {
            throw new InvalidArgumentException('Goat ram timers are outside their supported bounds.');
        }
        if (($ramming && ($ramTicks === 0 || $ramTargetUniqueId === null))
            || (!$ramming && ($ramTicks !== 0 || $ramTargetUniqueId !== null))) {
            throw new InvalidArgumentException('Goat ram state and target are inconsistent.');
        }
        $this->ramTicks = $ramTicks;
        $this->ramCooldownTicks = $ramCooldownTicks;
        $this->ramTargetUniqueId = $ramTargetUniqueId === null ? null : EntityUuid::validate($ramTargetUniqueId);
    }

    public function isScreaming(): bool
    {
        return $this->screaming;
    }

    public function hasLeftHorn(): bool
    {
        return $this->leftHorn;
    }

    public function hasRightHorn(): bool
    {
        return $this->rightHorn;
    }

    public function isRamming(): bool
    {
        return $this->ramming;
    }

    public function hasExclusiveAiActivity(): bool
    {
        return $this->ramming;
    }

    public function modifyIncomingDamage(float $damage, EntityDamageCause $cause): float
    {
        return $cause === EntityDamageCause::FALL
            ? max(0.0, $damage - self::FALL_DAMAGE_REDUCTION)
            : $damage;
    }

    /** @internal Authoritative ram-state mutation. */
    public function setRamming(bool $ramming): void
    {
        if ($this->ramming !== $ramming) {
            $this->ramming = $ramming;
            $this->markPresentationChanged();
        }
    }

    /** @internal */
    public function advanceRamTimers(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Goat ram timer advance is outside its supported bound.');
        }
        $this->ramTicks = max(0, $this->ramTicks - $ticks);
        $this->ramCooldownTicks = max(0, $this->ramCooldownTicks - $ticks);
        if ($this->ramTicks === 0 && $this->ramming) {
            $this->finishRam();
        } elseif ($this->ramTicks > 0 || $this->ramCooldownTicks > 0) {
            $this->markChanged();
        }
    }

    /** @internal */
    public function canBeginRam(): bool
    {
        return !$this->isBaby() && !$this->ramming && $this->ramCooldownTicks === 0;
    }

    public function getRamTargetUniqueId(): ?string
    {
        return $this->ramTargetUniqueId;
    }

    public function isRamTargetPlayer(): bool
    {
        return $this->ramTargetPlayer;
    }

    /** @internal */
    public function beginRam(string $targetUniqueId, bool $playerTarget = true): void
    {
        if (!$this->canBeginRam()) {
            return;
        }
        $this->ramTargetUniqueId = EntityUuid::validate($targetUniqueId);
        $this->ramTargetPlayer = $playerTarget;
        $this->ramTicks = 100;
        $this->setRamming(true);
    }

    /** @internal */
    public function finishRam(): void
    {
        $this->ramTicks = 0;
        $this->ramCooldownTicks = $this->screaming ? 600 : 1_200;
        $this->ramTargetUniqueId = null;
        $this->ramTargetPlayer = true;
        $this->setRamming(false);
        $this->markChanged();
    }

    /** @internal Returns true when a horn was removed. */
    public function loseHorn(): bool
    {
        if ($this->leftHorn) {
            $this->setHorns(false, $this->rightHorn);
            return true;
        }
        if ($this->rightHorn) {
            $this->setHorns(false, false);
            return true;
        }

        return false;
    }

    /** @internal Authoritative species-state mutation. */
    public function setHorns(bool $left, bool $right): void
    {
        if ($this->leftHorn !== $left || $this->rightHorn !== $right) {
            $this->leftHorn = $left;
            $this->rightHorn = $right;
            $this->markPresentationChanged();
        }
    }

    /** @return array{screaming: bool, leftHorn: bool, rightHorn: bool, ramming: bool, ramTicks: int, ramCooldownTicks: int, ramTargetUniqueId: ?string, ramTargetPlayer: bool} */
    protected function speciesPersistenceData(): array
    {
        return [
            'screaming' => $this->screaming,
            'leftHorn' => $this->leftHorn,
            'rightHorn' => $this->rightHorn,
            'ramming' => $this->ramming,
            'ramTicks' => $this->ramTicks,
            'ramCooldownTicks' => $this->ramCooldownTicks,
            'ramTargetUniqueId' => $this->ramTargetUniqueId,
            'ramTargetPlayer' => $this->ramTargetPlayer,
        ];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if ($variant !== null || !is_bool($data['screaming']) || !is_bool($data['leftHorn'])
            || !is_bool($data['rightHorn']) || !is_bool($data['ramming'])
            || !is_int($data['ramTicks']) || !is_int($data['ramCooldownTicks'])
            || ($data['ramTargetUniqueId'] !== null && !is_string($data['ramTargetUniqueId']))
            || !is_bool($data['ramTargetPlayer'])
            || $data['ramTicks'] < 0 || $data['ramTicks'] > 200
            || $data['ramCooldownTicks'] < 0 || $data['ramCooldownTicks'] > 6_000) {
            throw new InvalidArgumentException('Persisted goat state is malformed.');
        }
        if (($data['ramming'] && ($data['ramTicks'] === 0 || $data['ramTargetUniqueId'] === null))
            || (!$data['ramming'] && ($data['ramTicks'] !== 0 || $data['ramTargetUniqueId'] !== null))) {
            throw new InvalidArgumentException('Persisted goat ram state is inconsistent.');
        }
        $this->screaming = $data['screaming'];
        $this->leftHorn = $data['leftHorn'];
        $this->rightHorn = $data['rightHorn'];
        $this->ramming = $data['ramming'];
        $this->ramTicks = $data['ramTicks'];
        $this->ramCooldownTicks = $data['ramCooldownTicks'];
        $this->ramTargetUniqueId = $data['ramTargetUniqueId'] === null
            ? null
            : EntityUuid::validate($data['ramTargetUniqueId']);
        $this->ramTargetPlayer = $data['ramTargetPlayer'];
    }
}
