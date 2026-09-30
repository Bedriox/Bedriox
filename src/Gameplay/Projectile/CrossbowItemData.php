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

namespace Bedriox\Server\Gameplay\Projectile;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Server\Player\InventoryStack;

/** Authoritative charged-projectile state stored with a crossbow item. */
final class CrossbowItemData
{
    private const string CHARGED_ITEM = 'chargedItem';
    private const string IDENTIFIER = 'Name';
    private const string COUNT = 'Count';
    private const string DAMAGE = 'Damage';
    private const string WAS_PICKED_UP = 'WasPickedUp';
    private const string ITEM_DAMAGE = 'BedrioxDamage';
    private const string ITEM_NBT = 'BedrioxItemNbt';

    public static function chargedProjectile(?ItemNbt $nbt): ?InventoryStack
    {
        $charged = $nbt?->tag(self::CHARGED_ITEM);
        $values = $charged?->value();
        if ($charged?->type() !== TagType::COMPOUND || !is_array($values)) {
            return null;
        }
        $identifier = self::string($values, self::IDENTIFIER);
        $count = self::integer($values, self::COUNT);
        $auxValue = self::integer($values, self::DAMAGE);
        $damage = self::integer($values, self::ITEM_DAMAGE) ?? 0;
        $encodedNbt = self::string($values, self::ITEM_NBT);
        if ($identifier === null || $count !== 1 || $auxValue === null || $encodedNbt === null) {
            return null;
        }
        $binaryNbt = base64_decode($encodedNbt, true);
        if ($binaryNbt === false) {
            return null;
        }

        try {
            return new InventoryStack(
                $identifier,
                1,
                1,
                damage: $damage,
                nbt: $binaryNbt === '' ? null : ItemNbt::fromBinary($binaryNbt),
                auxValue: $auxValue,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function withChargedProjectile(?ItemNbt $nbt, InventoryStack $projectile): ItemNbt
    {
        return ($nbt ?? ItemNbt::empty())->withTag(self::CHARGED_ITEM, Tag::compound([
            self::IDENTIFIER => Tag::string($projectile->identifier),
            self::COUNT => Tag::byte(1),
            self::DAMAGE => Tag::short($projectile->auxValue),
            self::WAS_PICKED_UP => Tag::byte(0),
            self::ITEM_DAMAGE => Tag::int($projectile->damage),
            self::ITEM_NBT => Tag::string(base64_encode($projectile->nbt?->toBinary() ?? '')),
        ]));
    }

    public static function withoutChargedProjectile(?ItemNbt $nbt): ?ItemNbt
    {
        if ($nbt === null) {
            return null;
        }
        $updated = $nbt->withoutTag(self::CHARGED_ITEM);

        return $updated->isEmpty() ? null : $updated;
    }

    /** @param array<string|int, Tag|int> $values */
    private static function string(array $values, string $name): ?string
    {
        $tag = $values[$name] ?? null;

        return $tag instanceof Tag && $tag->type() === TagType::STRING && is_string($tag->value())
            ? $tag->value()
            : null;
    }

    /** @param array<string|int, Tag|int> $values */
    private static function integer(array $values, string $name): ?int
    {
        $tag = $values[$name] ?? null;
        if (!$tag instanceof Tag || !in_array($tag->type(), [TagType::BYTE, TagType::SHORT, TagType::INT], true)) {
            return null;
        }
        $value = $tag->value();

        return is_int($value) ? $value : null;
    }

    private function __construct() {}
}
