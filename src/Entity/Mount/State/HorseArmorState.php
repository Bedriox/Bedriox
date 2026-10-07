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

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

trait HorseArmorState
{
    /** @var list<string> */
    private const array SUPPORTED_HORSE_ARMOR = [
        'minecraft:leather_horse_armor',
        'minecraft:copper_horse_armor',
        'minecraft:iron_horse_armor',
        'minecraft:golden_horse_armor',
        'minecraft:diamond_horse_armor',
        'minecraft:netherite_horse_armor',
    ];

    private ?ItemStack $horseArmor = null;
    private bool $horseArmorProjectionChanged = false;

    final public function getHorseArmor(): ?ItemStack
    {
        return $this->horseArmor;
    }

    public function getBodyEquipment(): ?ItemStack
    {
        return $this->horseArmor;
    }

    public function drainBodyEquipmentChange(): bool
    {
        $changed = $this->horseArmorProjectionChanged;
        $this->horseArmorProjectionChanged = false;

        return $changed;
    }

    /** @internal Authoritative horse-container mutation. */
    final public function setHorseArmor(?ItemStack $armor): void
    {
        self::validateHorseArmor($armor);
        if ($this->horseArmor != $armor) {
            $this->horseArmor = $armor;
            $this->horseArmorProjectionChanged = true;
            $this->markChanged();
        }
    }

    /** @return list<string> */
    final public static function supportedHorseArmorIdentifiers(): array
    {
        return self::SUPPORTED_HORSE_ARMOR;
    }

    /** @return array{identifier: string, count: int, damage: int, auxValue: int, nbt: ?string}|null */
    final protected function horseArmorPersistenceData(): ?array
    {
        return $this->horseArmor === null ? null : [
            'identifier' => $this->horseArmor->identifier,
            'count' => $this->horseArmor->count,
            'damage' => $this->horseArmor->damage,
            'auxValue' => $this->horseArmor->auxValue,
            'nbt' => $this->horseArmor->nbt === null ? null : base64_encode($this->horseArmor->nbt->toBinary()),
        ];
    }

    final protected function restoreHorseArmor(mixed $encoded): void
    {
        if ($encoded === null) {
            $this->horseArmor = null;
            return;
        }
        if (!is_array($encoded) || array_keys($encoded) !== ['identifier', 'count', 'damage', 'auxValue', 'nbt']
            || !is_string($encoded['identifier']) || !is_int($encoded['count'])
            || !is_int($encoded['damage']) || !is_int($encoded['auxValue'])
            || ($encoded['nbt'] !== null && !is_string($encoded['nbt']))) {
            throw new InvalidArgumentException('Persisted horse armor is malformed.');
        }
        $binary = $encoded['nbt'] === null ? null : base64_decode($encoded['nbt'], true);
        if ($encoded['nbt'] !== null && $binary === false) {
            throw new InvalidArgumentException('Persisted horse armor NBT is malformed.');
        }
        $armor = new ItemStack(
            $encoded['identifier'],
            $encoded['count'],
            $encoded['damage'],
            $binary === null ? null : ItemNbt::fromBinary($binary),
            $encoded['auxValue'],
        );
        self::validateHorseArmor($armor);
        $this->horseArmor = $armor;
    }

    private static function validateHorseArmor(?ItemStack $armor): void
    {
        if ($armor !== null && ($armor->count !== 1 || !in_array($armor->identifier, self::SUPPORTED_HORSE_ARMOR, true))) {
            throw new InvalidArgumentException('Horse body equipment only accepts one supported horse-armor item.');
        }
    }
}
