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

use Bedriox\Api\Entity\Value\FoxVariant;
use Bedriox\Api\Entity\Vanilla\Fox;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\ExclusiveAiActivity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class FoxEntity extends LandBreedableAnimalEntity implements ExclusiveAiActivity, Fox
{
    private bool $totemPresentationPending = false;
    private int $pounceTicks = 0;
    private bool $pounceAirborne = false;
    private int $faceplantedTicks = 0;
    private ?string $trustedDefenseTargetUniqueId = null;
    private int $trustedDefenseTicks = 0;

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
        private FoxVariant $variant = FoxVariant::RED,
        private ?string $primaryTrustedPlayerUniqueId = null,
        private ?string $secondaryTrustedPlayerUniqueId = null,
        private bool $sleeping = false,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::fox(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::cautious('fox', ['minecraft:sweet_berries', 'minecraft:glow_berries'], 0.10), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby);
        $this->primaryTrustedPlayerUniqueId = $primaryTrustedPlayerUniqueId === null
            ? null
            : EntityUuid::validate($primaryTrustedPlayerUniqueId);
        $this->secondaryTrustedPlayerUniqueId = $secondaryTrustedPlayerUniqueId === null
            ? null
            : EntityUuid::validate($secondaryTrustedPlayerUniqueId);
    }

    public function getVariant(): FoxVariant
    {
        return $this->variant;
    }

    public function isTrusting(): bool
    {
        return $this->primaryTrustedPlayerUniqueId !== null || $this->secondaryTrustedPlayerUniqueId !== null;
    }

    public function trustsPlayer(string $playerUniqueId): bool
    {
        $playerUniqueId = strtolower(EntityUuid::validate($playerUniqueId));

        return ($this->primaryTrustedPlayerUniqueId !== null && strtolower($this->primaryTrustedPlayerUniqueId) === $playerUniqueId)
            || ($this->secondaryTrustedPlayerUniqueId !== null && strtolower($this->secondaryTrustedPlayerUniqueId) === $playerUniqueId);
    }

    public function isSleeping(): bool
    {
        return $this->sleeping;
    }

    public function isPouncing(): bool
    {
        return $this->pounceTicks > 0;
    }

    public function isFaceplanted(): bool
    {
        return $this->faceplantedTicks > 0;
    }

    public function hasExclusiveAiActivity(): bool
    {
        return $this->sleeping || $this->isPouncing() || $this->isFaceplanted()
            || $this->getTrustedDefenseTargetUniqueId() !== null;
    }

    /** @internal Authoritative hunting-state mutation. */
    public function beginPounce(int $ticks = 20): void
    {
        if ($ticks < 1 || $ticks > 40) {
            throw new InvalidArgumentException('Fox pounce duration is outside its supported bounds.');
        }
        $this->pounceTicks = $ticks;
        $this->pounceAirborne = false;
        $this->faceplantedTicks = 0;
        $this->setSleeping(false);
        $this->markPresentationChanged();
    }

    /** @internal Authoritative snow-dive state mutation. */
    public function beginFaceplant(int $ticks = 40): void
    {
        if ($ticks < 1 || $ticks > 100) {
            throw new InvalidArgumentException('Fox faceplant duration is outside its supported bounds.');
        }
        $this->pounceTicks = 0;
        $this->pounceAirborne = false;
        $this->faceplantedTicks = $ticks;
        $this->setSleeping(false);
        $this->markPresentationChanged();
    }

    /** @internal Advances transient pounce and snow-dive presentation. */
    public function advanceHuntingState(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Fox hunting-state advance is outside its supported bounds.');
        }
        $wasPouncing = $this->pounceTicks > 0;
        $wasFaceplanted = $this->faceplantedTicks > 0;
        $this->pounceTicks = max(0, $this->pounceTicks - $ticks);
        $this->faceplantedTicks = max(0, $this->faceplantedTicks - $ticks);
        if ($wasPouncing !== ($this->pounceTicks > 0) || $wasFaceplanted !== ($this->faceplantedTicks > 0)) {
            if ($this->pounceTicks === 0) {
                $this->pounceAirborne = false;
            }
            $this->markPresentationChanged();
        }
    }

    /** @internal Records that a pounce has actually left the ground. */
    public function recordPounceAirborne(): void
    {
        if ($this->isPouncing()) {
            $this->pounceAirborne = true;
        }
    }

    /** @internal */
    public function hasAirbornePounce(): bool
    {
        return $this->isPouncing() && $this->pounceAirborne;
    }

    /** @internal Completes a physics-confirmed pounce landing. */
    public function completePounce(bool $faceplanted): void
    {
        if (!$this->isPouncing()) {
            return;
        }
        $this->pounceTicks = 0;
        $this->pounceAirborne = false;
        if ($faceplanted) {
            $this->beginFaceplant();
            return;
        }
        $this->markPresentationChanged();
    }

    /** @internal Interrupts hunting when a higher-priority threat takes ownership. */
    public function cancelPounce(): void
    {
        if ($this->pounceTicks === 0 && !$this->pounceAirborne) {
            return;
        }
        $this->pounceTicks = 0;
        $this->pounceAirborne = false;
        $this->markPresentationChanged();
    }

    /** @internal Records a bounded attacker of a trusted player. */
    public function defendTrustedPlayerAgainst(string $targetUniqueId, int $ticks = 200): void
    {
        if ($ticks < 1 || $ticks > 1_200) {
            throw new InvalidArgumentException('Fox trusted-defense duration is outside its supported bounds.');
        }
        $this->trustedDefenseTargetUniqueId = EntityUuid::validate($targetUniqueId);
        $this->trustedDefenseTicks = $ticks;
        $this->setSleeping(false);
        $this->markChanged();
    }

    /** @internal */
    public function getTrustedDefenseTargetUniqueId(): ?string
    {
        return $this->trustedDefenseTicks > 0 ? $this->trustedDefenseTargetUniqueId : null;
    }

    /** @internal */
    public function clearTrustedDefenseTarget(): void
    {
        if ($this->trustedDefenseTargetUniqueId !== null || $this->trustedDefenseTicks !== 0) {
            $this->trustedDefenseTargetUniqueId = null;
            $this->trustedDefenseTicks = 0;
            $this->markChanged();
        }
    }

    /** @internal */
    public function advanceTrustedDefense(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Fox trusted-defense advance is outside its supported bounds.');
        }
        if ($this->trustedDefenseTicks === 0) {
            return;
        }
        $this->trustedDefenseTicks = max(0, $this->trustedDefenseTicks - $ticks);
        if ($this->trustedDefenseTicks === 0) {
            $this->trustedDefenseTargetUniqueId = null;
        }
        $this->markChanged();
    }

    /** @internal Records a one-shot totem animation after authoritative survival. */
    public function markTotemConsumed(): void
    {
        $this->totemPresentationPending = true;
        $this->markPresentationChanged();
    }

    /** @internal */
    public function takeTotemPresentation(): bool
    {
        $pending = $this->totemPresentationPending;
        $this->totemPresentationPending = false;

        return $pending;
    }

    public function getTrustedPlayerUniqueIds(): array
    {
        return array_values(array_filter([
            $this->primaryTrustedPlayerUniqueId,
            $this->secondaryTrustedPlayerUniqueId,
        ], static fn(?string $value): bool => $value !== null));
    }

    /** @internal Authoritative offspring trust mutation. */
    public function addTrustedPlayerUniqueId(string $playerUniqueId): void
    {
        $playerUniqueId = EntityUuid::validate($playerUniqueId);
        if ($this->trustsPlayer($playerUniqueId)) {
            return;
        }
        if ($this->primaryTrustedPlayerUniqueId === null) {
            $this->primaryTrustedPlayerUniqueId = $playerUniqueId;
        } else {
            $this->secondaryTrustedPlayerUniqueId = $playerUniqueId;
        }
        $this->markPresentationChanged();
    }

    /** @internal Authoritative rest-state mutation. */
    public function setSleeping(bool $sleeping): void
    {
        if ($this->sleeping !== $sleeping) {
            $this->sleeping = $sleeping;
            if ($sleeping) {
                $motion = $this->getMotion();
                $this->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
            }
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setVariant(FoxVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            $this->markPresentationChanged();
        }
    }

    protected function speciesPersistenceVariant(): int
    {
        return $this->variant->value;
    }

    /** @return array{primaryTrustedPlayerUniqueId: ?string, secondaryTrustedPlayerUniqueId: ?string, sleeping: bool} */
    protected function speciesPersistenceData(): array
    {
        return [
            'primaryTrustedPlayerUniqueId' => $this->primaryTrustedPlayerUniqueId,
            'secondaryTrustedPlayerUniqueId' => $this->secondaryTrustedPlayerUniqueId,
            'sleeping' => $this->sleeping,
        ];
    }

    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if (!is_int($variant) || ($foxVariant = FoxVariant::tryFrom($variant)) === null
            || ($data['primaryTrustedPlayerUniqueId'] !== null && !is_string($data['primaryTrustedPlayerUniqueId']))
            || ($data['secondaryTrustedPlayerUniqueId'] !== null && !is_string($data['secondaryTrustedPlayerUniqueId']))
            || !is_bool($data['sleeping'])) {
            throw new InvalidArgumentException('Persisted fox variant is unsupported.');
        }
        $this->variant = $foxVariant;
        $this->primaryTrustedPlayerUniqueId = $data['primaryTrustedPlayerUniqueId'] === null
            ? null
            : EntityUuid::validate($data['primaryTrustedPlayerUniqueId']);
        $this->secondaryTrustedPlayerUniqueId = $data['secondaryTrustedPlayerUniqueId'] === null
            ? null
            : EntityUuid::validate($data['secondaryTrustedPlayerUniqueId']);
        $this->sleeping = $data['sleeping'];
    }
}
