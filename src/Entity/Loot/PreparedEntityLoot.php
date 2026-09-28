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

namespace Bedriox\Server\Entity\Loot;

use Bedriox\Api\Inventory\ItemStack;

final class PreparedEntityLoot
{
    private bool $evaluated = false;

    /** @var list<ItemStack> */
    private array $drops = [];

    public function __construct(
        private readonly LootTable $table,
        private readonly LootContext $context,
        private readonly LootRandomSource $random,
        private readonly LootOutputNormalizer $normalizer,
    ) {}

    /** @return list<ItemStack> */
    public function drops(): array
    {
        if (!$this->evaluated) {
            $drops = $this->table->roll($this->context, $this->random);
            foreach ($this->context->equipment as $equipped) {
                if ($equipped->dropChance >= 1.0
                    || ($equipped->dropChance > 0.0
                        && $this->random->nextInt(0, 999_999) < (int) floor($equipped->dropChance * 1_000_000.0))) {
                    $drops[] = $equipped->item;
                }
            }
            $this->drops = $this->normalizer->normalize($drops);
            $this->evaluated = true;
        }

        return $this->drops;
    }
}
