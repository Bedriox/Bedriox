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

namespace Bedriox\Server\Entity\Mount\State;

use Bedriox\Api\Entity\Value\LlamaVariant;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\EntityUuid;
use InvalidArgumentException;

trait LlamaEquipmentState
{
    use AnimalStorageState;

    private int $strength = 1;
    private LlamaVariant $variant = LlamaVariant::CREAMY;
    private bool $chested = false;
    private ?WoolColor $carpetColor = null;
    private bool $carpetProjectionChanged = false;
    private ?string $caravanLeaderUniqueId = null;
    private ?string $retaliationTargetUniqueId = null;
    private int $retaliationTicks = 0;
    private int $spitCooldownTicks = 0;

    final public function getStrength(): int
    {
        return $this->strength;
    }

    final public function getVariant(): LlamaVariant
    {
        return $this->variant;
    }

    /** @internal Authoritative inherited-state mutation. */
    final public function setStrength(int $strength): void
    {
        self::validateLlamaStrength($strength);
        if ($this->strength !== $strength) {
            $this->strength = $strength;
            $this->resizeEmptyAnimalStorage($strength * 3);
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative inherited-state mutation. */
    final public function setVariant(LlamaVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            $this->markPresentationChanged();
        }
    }

    final public function hasChest(): bool
    {
        return $this->chested;
    }

    final public function getStorageSlotCount(): int
    {
        return $this->chested ? $this->strength * 3 : 0;
    }

    final public function getCarpetColor(): ?WoolColor
    {
        return $this->carpetColor;
    }

    final public function getBodyEquipment(): ?ItemStack
    {
        return $this->carpetColor === null
            ? null
            : new ItemStack('minecraft:' . $this->carpetColor->value . '_carpet', 1);
    }

    final public function drainBodyEquipmentChange(): bool
    {
        $changed = $this->carpetProjectionChanged;
        $this->carpetProjectionChanged = false;

        return $changed;
    }

    final public function getCaravanLeaderUniqueId(): ?string
    {
        return $this->caravanLeaderUniqueId;
    }

    final public function hasExclusiveAiActivity(): bool
    {
        return $this->caravanLeaderUniqueId !== null;
    }

    final public function getRetaliationTargetUniqueId(): ?string
    {
        return $this->retaliationTicks > 0 ? $this->retaliationTargetUniqueId : null;
    }

    /** @internal Authoritative retaliation mutation. */
    final public function setRetaliationTargetUniqueId(?string $uniqueId, int $ticks = 200): void
    {
        if ($uniqueId === null) {
            $this->retaliationTargetUniqueId = null;
            $this->retaliationTicks = 0;
            $this->markChanged();

            return;
        }
        if ($ticks < 1 || $ticks > 1_200) {
            throw new InvalidArgumentException('Llama retaliation duration is outside its supported bounds.');
        }
        $this->retaliationTargetUniqueId = EntityUuid::validate($uniqueId);
        $this->retaliationTicks = $ticks;
        $this->markChanged();
    }

    /** @internal */
    final public function canSpit(): bool
    {
        return $this->spitCooldownTicks === 0;
    }

    /** @internal */
    final public function markSpat(int $cooldownTicks = 40): void
    {
        if ($cooldownTicks < 1 || $cooldownTicks > 200) {
            throw new InvalidArgumentException('Llama spit cooldown is outside its supported bounds.');
        }
        $this->spitCooldownTicks = $cooldownTicks;
        $this->markChanged();
    }

    /** @internal */
    final public function advanceLlamaCombatState(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Llama combat-state advance is outside its supported bounds.');
        }
        $changed = false;
        if ($this->retaliationTicks > 0) {
            $this->retaliationTicks = max(0, $this->retaliationTicks - $ticks);
            $changed = true;
            if ($this->retaliationTicks === 0) {
                $this->retaliationTargetUniqueId = null;
            }
        }
        if ($this->spitCooldownTicks > 0) {
            $this->spitCooldownTicks = max(0, $this->spitCooldownTicks - $ticks);
            $changed = true;
        }
        if ($changed) {
            $this->markChanged();
        }
    }

    /** @internal Authoritative caravan-chain mutation. */
    final public function setCaravanLeaderUniqueId(?string $uniqueId): void
    {
        if ($uniqueId !== null) {
            $uniqueId = EntityUuid::validate($uniqueId);
        }
        if ($this->caravanLeaderUniqueId !== $uniqueId) {
            $this->caravanLeaderUniqueId = $uniqueId;
            $this->markChanged();
        }
    }

    /** @internal Authoritative interaction mutation. */
    final public function setChested(bool $chested): void
    {
        if ($this->chested !== $chested) {
            $this->chested = $chested;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative interaction mutation. */
    final public function setCarpetColor(?WoolColor $color): void
    {
        if ($this->carpetColor !== $color) {
            $this->carpetColor = $color;
            $this->carpetProjectionChanged = true;
            $this->markChanged();
        }
    }

    final protected function initializeLlamaEquipmentState(
        string $uniqueId,
        int $strength,
        bool $chested,
        ?WoolColor $carpetColor,
        LlamaVariant $variant,
    ): void {
        self::validateLlamaStrength($strength);
        $this->strength = $strength;
        $this->variant = $variant;
        $this->chested = $chested;
        $this->carpetColor = $carpetColor;
        $this->initializeAnimalStorage($uniqueId, $strength * 3);
    }

    /** @return array{strength: int, variant: int, chested: bool, carpetColor: ?string, caravanLeaderUniqueId: ?string, items: list<mixed>} */
    final protected function llamaEquipmentPersistenceData(): array
    {
        return [
            'strength' => $this->strength,
            'variant' => $this->variant->value,
            'chested' => $this->chested,
            'carpetColor' => $this->carpetColor?->value,
            'caravanLeaderUniqueId' => $this->caravanLeaderUniqueId,
            'items' => $this->animalStoragePersistenceData(),
        ];
    }

    /** @param array<mixed> $data */
    final protected function restoreLlamaEquipmentPersistenceData(array $data): void
    {
        if (!is_int($data['strength']) || !is_int($data['variant']) || !is_bool($data['chested'])
            || ($data['carpetColor'] !== null && !is_string($data['carpetColor']))
            || ($data['caravanLeaderUniqueId'] !== null && !is_string($data['caravanLeaderUniqueId']))) {
            throw new InvalidArgumentException('Persisted llama equipment state is malformed.');
        }
        self::validateLlamaStrength($data['strength']);
        $variant = LlamaVariant::tryFrom($data['variant']);
        if ($variant === null) {
            throw new InvalidArgumentException('Persisted llama variant is unsupported.');
        }
        $color = $data['carpetColor'] === null ? null : WoolColor::tryFrom($data['carpetColor']);
        if ($data['carpetColor'] !== null && $color === null) {
            throw new InvalidArgumentException('Persisted llama carpet color is unsupported.');
        }
        $this->strength = $data['strength'];
        $this->variant = $variant;
        $this->chested = $data['chested'];
        $this->carpetColor = $color;
        $this->caravanLeaderUniqueId = $data['caravanLeaderUniqueId'] === null
            ? null
            : EntityUuid::validate($data['caravanLeaderUniqueId']);
        $this->resizeEmptyAnimalStorage($this->strength * 3);
        $this->restoreAnimalStorage($data['items'] ?? null);
    }

    private static function validateLlamaStrength(int $strength): void
    {
        if ($strength < 1 || $strength > 5) {
            throw new InvalidArgumentException('Llama strength must be between one and five.');
        }
    }
}
