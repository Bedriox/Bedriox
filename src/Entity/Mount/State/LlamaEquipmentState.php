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

use Bedriox\Api\Entity\Value\WoolColor;
use InvalidArgumentException;

trait LlamaEquipmentState
{
    private int $strength = 1;
    private bool $chested = false;
    private ?WoolColor $carpetColor = null;

    final public function getStrength(): int
    {
        return $this->strength;
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
            $this->markPresentationChanged();
        }
    }

    final protected function initializeLlamaEquipmentState(int $strength, bool $chested, ?WoolColor $carpetColor): void
    {
        self::validateLlamaStrength($strength);
        $this->strength = $strength;
        $this->chested = $chested;
        $this->carpetColor = $carpetColor;
    }

    /** @return array{strength: int, chested: bool, carpetColor: ?string} */
    final protected function llamaEquipmentPersistenceData(): array
    {
        return [
            'strength' => $this->strength,
            'chested' => $this->chested,
            'carpetColor' => $this->carpetColor?->value,
        ];
    }

    /** @param array<mixed> $data */
    final protected function restoreLlamaEquipmentPersistenceData(array $data): void
    {
        if (!is_int($data['strength']) || !is_bool($data['chested'])
            || ($data['carpetColor'] !== null && !is_string($data['carpetColor']))) {
            throw new InvalidArgumentException('Persisted llama equipment state is malformed.');
        }
        self::validateLlamaStrength($data['strength']);
        $color = $data['carpetColor'] === null ? null : WoolColor::tryFrom($data['carpetColor']);
        if ($data['carpetColor'] !== null && $color === null) {
            throw new InvalidArgumentException('Persisted llama carpet color is unsupported.');
        }
        $this->strength = $data['strength'];
        $this->chested = $data['chested'];
        $this->carpetColor = $color;
    }

    private static function validateLlamaStrength(int $strength): void
    {
        if ($strength < 1 || $strength > 5) {
            throw new InvalidArgumentException('Llama strength must be between one and five.');
        }
    }
}
