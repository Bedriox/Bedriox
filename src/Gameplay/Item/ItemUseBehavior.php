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

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Api\Potion\PotionContainer;
use InvalidArgumentException;

/** Bounded server-owned behavior for an admitted canonical item. */
final readonly class ItemUseBehavior
{
    public function __construct(
        public string $identifier,
        public int $useDurationTicks,
        public ?ConsumableDefinition $consumable = null,
        public int $cooldownTicks = 0,
        public ?string $owner = null,
        public ItemUseKind $kind = ItemUseKind::CONSUME,
        public ?ConsumableEffectDefinition $effects = null,
        public ?PotionContainer $throwablePotion = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Item behavior identifier must be canonical and namespaced.');
        }
        if ($useDurationTicks < 0 || $useDurationTicks > 1_200
            || (in_array($kind, [ItemUseKind::CONSUME, ItemUseKind::CHARGE], true) && $useDurationTicks < 1)
            || (!in_array($kind, [ItemUseKind::CONSUME, ItemUseKind::CHARGE], true) && $useDurationTicks !== 0)) {
            throw new InvalidArgumentException('Item use duration is invalid for its use kind.');
        }
        if ($cooldownTicks < 0 || $cooldownTicks > 72_000) {
            throw new InvalidArgumentException('Item cooldown must be between zero and one hour.');
        }
        if (($kind === ItemUseKind::CONSUME) !== ($consumable !== null)) {
            throw new InvalidArgumentException('Only consume behavior may include nutrition data.');
        }
        if ($effects !== null && $kind !== ItemUseKind::CONSUME) {
            throw new InvalidArgumentException('Only consume behavior may include effect mutations.');
        }
        if ($throwablePotion !== null && (!$throwablePotion->isThrowable() || $kind !== ItemUseKind::INSTANT)) {
            throw new InvalidArgumentException('Throwable potion behavior must use a throwable instant container.');
        }
        if ($owner !== null && ($owner === '' || strlen($owner) > 64
            || preg_match('/^[A-Za-z0-9_.-]+$/D', $owner) !== 1)) {
            throw new InvalidArgumentException('Item behavior owner is invalid.');
        }
    }
}
