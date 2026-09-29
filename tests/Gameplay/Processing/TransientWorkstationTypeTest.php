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

namespace Bedriox\Server\Tests\Gameplay\Processing;

use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Server\Gameplay\Processing\TransientWorkstationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransientWorkstationTypeTest extends TestCase
{
    /** @return iterable<string, array{string, ContainerType, int}> */
    public static function stations(): iterable
    {
        yield 'stonecutter' => ['minecraft:stonecutter', ContainerType::STONECUTTER, 2];
        yield 'smithing table' => ['minecraft:smithing_table', ContainerType::SMITHING_TABLE, 4];
        yield 'anvil' => ['minecraft:anvil', ContainerType::ANVIL, 3];
        yield 'chipped anvil' => ['minecraft:chipped_anvil', ContainerType::ANVIL, 3];
        yield 'damaged anvil' => ['minecraft:damaged_anvil', ContainerType::ANVIL, 3];
        yield 'grindstone' => ['minecraft:grindstone', ContainerType::GRINDSTONE, 3];
        yield 'enchanting table' => ['minecraft:enchanting_table', ContainerType::ENCHANTING_TABLE, 2];
        yield 'loom' => ['minecraft:loom', ContainerType::LOOM, 4];
        yield 'cartography table' => ['minecraft:cartography_table', ContainerType::CARTOGRAPHY_TABLE, 3];
    }

    #[DataProvider('stations')]
    public function testMapsCanonicalBlocksToFixedContainerLayouts(
        string $identifier,
        ContainerType $containerType,
        int $slots,
    ): void {
        $type = TransientWorkstationType::fromBlockIdentifier($identifier);

        self::assertNotNull($type);
        self::assertSame($containerType, $type->containerType());
        self::assertSame($slots, $type->slotCount());
    }

    public function testRejectsUnrelatedBlocks(): void
    {
        self::assertNull(TransientWorkstationType::fromBlockIdentifier('minecraft:stone'));
    }
}
