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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Capability\Shearable;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Immutable notification emitted after authoritative shearing commits. */
final class EntityShearedEvent extends Event implements PostEvent
{
    /** @var list<ItemStack> */
    public readonly array $drops;

    /** @param list<ItemStack> $drops */
    public function __construct(
        public readonly Player $player,
        public readonly Shearable $entity,
        public readonly ItemStack $tool,
        array $drops,
    ) {
        $this->drops = self::validateDrops($drops);
    }

    /**
     * @param array<mixed> $drops
     * @return list<ItemStack>
     */
    private static function validateDrops(array $drops): array
    {
        if (!array_is_list($drops) || count($drops) > EntityShearEvent::MAXIMUM_DROPS) {
            throw new InvalidArgumentException('Committed entity shear drops must be a bounded list.');
        }
        foreach ($drops as $drop) {
            if (!$drop instanceof ItemStack) {
                throw new InvalidArgumentException('Committed entity shear drops must contain ItemStack values.');
            }
        }
        return $drops;
    }
}
