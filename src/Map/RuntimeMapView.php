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

namespace Bedriox\Server\Map;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Map\MapScale;
use Bedriox\Api\Map\MapView;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\World\DimensionType;

final class RuntimeMapView implements MapView
{
    public function __construct(
        private readonly MapRegistry $registry,
        private MapRecord $record,
    ) {}

    public function getId(): int
    {
        return $this->record->id;
    }

    public function getDimensionType(): DimensionType
    {
        return $this->record->dimension;
    }

    public function getScale(): MapScale
    {
        return $this->record->scale;
    }

    public function isLocked(): bool
    {
        return $this->record->locked;
    }

    public function setLocked(bool $locked): void
    {
        $next = $this->record->withLocked($locked);
        if ($next !== $this->record) {
            $this->registry->replace($next);
            $this->record = $next;
        }
    }

    public function createItem(): ItemStack
    {
        $nbt = ItemNbt::empty()
            ->withTag('map_uuid', Tag::long($this->record->id))
            ->withTag('map_name_index', Tag::int($this->record->id))
            ->withTag('map_display_players', Tag::byte($this->record->tracking ? 1 : 0));

        return new ItemStack('minecraft:filled_map', 1, nbt: $nbt, auxValue: $this->record->locked ? 6 : 0);
    }
}
