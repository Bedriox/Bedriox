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

namespace Bedriox\Server\World\Physics;

use Bedriox\Data\CanonicalBlockState;
use InvalidArgumentException;

/** Immutable semantic registry for blocks which become falling actors when unsupported. */
final readonly class FallingBlockBehaviorRegistry
{
    /** @var array<string, FallingBlockBehavior> */
    private array $behaviors;

    /** @param array<string, FallingBlockBehavior> $behaviors */
    public function __construct(array $behaviors)
    {
        if (count($behaviors) > 256) {
            throw new InvalidArgumentException('Falling-block behavior registry is oversized.');
        }
        $validated = [];
        foreach ($behaviors as $identifier => $behavior) {
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
                throw new InvalidArgumentException('Falling-block behavior registration is invalid.');
            }
            $validated[$identifier] = $behavior;
        }
        ksort($validated, SORT_STRING);
        $this->behaviors = $validated;
    }

    public static function vanilla(): self
    {
        $ordinary = new FallingBlockBehavior();
        $behaviors = [
            'minecraft:sand' => $ordinary,
            'minecraft:red_sand' => $ordinary,
            'minecraft:gravel' => $ordinary,
            'minecraft:dragon_egg' => new FallingBlockBehavior(FallingBlockKind::DRAGON_EGG),
            'minecraft:anvil' => new FallingBlockBehavior(FallingBlockKind::ANVIL, damagePerBlock: 2.0, maximumDamage: 40.0),
            'minecraft:chipped_anvil' => new FallingBlockBehavior(FallingBlockKind::ANVIL, damagePerBlock: 2.0, maximumDamage: 40.0),
            'minecraft:damaged_anvil' => new FallingBlockBehavior(FallingBlockKind::ANVIL, damagePerBlock: 2.0, maximumDamage: 40.0),
        ];
        foreach ([
            'black', 'blue', 'brown', 'cyan', 'gray', 'green', 'light_blue', 'light_gray',
            'lime', 'magenta', 'orange', 'pink', 'purple', 'red', 'white', 'yellow',
        ] as $color) {
            $behaviors['minecraft:' . $color . '_concrete_powder'] = new FallingBlockBehavior(
                FallingBlockKind::CONCRETE_POWDER,
                'minecraft:' . $color . '_concrete',
            );
        }

        return new self($behaviors);
    }

    public function behavior(CanonicalBlockState|string $block): ?FallingBlockBehavior
    {
        $identifier = $block instanceof CanonicalBlockState ? $block->identifier() : $block;

        return $this->behaviors[$identifier] ?? null;
    }

    public function isFalling(CanonicalBlockState|string $block): bool
    {
        return $this->behavior($block) !== null;
    }
}
