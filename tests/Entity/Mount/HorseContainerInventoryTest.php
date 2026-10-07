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

namespace Bedriox\Server\Tests\Entity\Mount;

use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Mount\AnimalEquipmentSlotDeclaration;
use Bedriox\Server\Entity\Mount\AnimalEquipmentSlotType;
use Bedriox\Server\Entity\Mount\Inventory\HorseContainerInventory;
use Bedriox\Server\Entity\Vanilla\DonkeyEntity;
use Bedriox\Server\Entity\Vanilla\HorseEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Inventory\ContainerRevisionMismatchException;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HorseContainerInventoryTest extends TestCase
{
    public function testHorseEquipmentIsCompositeAuthoritativeAndPersistsAcrossReopenAndRestore(): void
    {
        $horse = new HorseEntity(
            EntityUuid::random(),
            10,
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: EntityUuid::random(),
        );
        $declarations = $this->horseDeclarations();
        $inventory = new HorseContainerInventory($horse, $declarations, null);

        self::assertSame(2, $inventory->size());
        self::assertSame([null, null], $inventory->contents());
        self::assertTrue($inventory->replaceContents([
            new ItemStack('minecraft:saddle', 1),
            new ItemStack('minecraft:diamond_horse_armor', 1, 8),
        ], $inventory->revision()));
        self::assertTrue($horse->isSaddled());
        self::assertSame('minecraft:diamond_horse_armor', $horse->getHorseArmor()?->identifier);

        $reopened = new HorseContainerInventory($horse, $declarations, null);
        self::assertSame('minecraft:saddle', $reopened->stackAt(0)?->identifier);
        self::assertSame(8, $reopened->stackAt(1)?->damage);

        $restored = new HorseEntity(
            EntityUuid::random(),
            11,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $restored->restorePersistenceState(null, 1, $horse->persistenceData());
        self::assertTrue($restored->isSaddled());
        self::assertSame('minecraft:diamond_horse_armor', $restored->getHorseArmor()?->identifier);
        self::assertSame(8, $restored->getHorseArmor()->damage);
    }

    public function testEquipmentValidationRejectsUnsupportedAndStackedItemsWithoutMutation(): void
    {
        $horse = new HorseEntity(EntityUuid::random(), 12, 'world', new Position(0.0, 64.0, 0.0));
        $inventory = new HorseContainerInventory($horse, $this->horseDeclarations(), null);

        foreach ([
            [new ItemStack('minecraft:stone', 1), null],
            [new ItemStack('minecraft:saddle', 2), null],
            [null, new ItemStack('minecraft:iron_chestplate', 1)],
        ] as $contents) {
            try {
                $inventory->replaceContents($contents, $inventory->revision());
                self::fail('Invalid horse equipment was accepted.');
            } catch (InvalidArgumentException) {
                self::assertFalse($horse->isSaddled());
                self::assertNull($horse->getHorseArmor());
            }
        }
    }

    public function testChestedHorseStorageFollowsEquipmentInOneCompositeLayout(): void
    {
        $donkey = new DonkeyEntity(
            EntityUuid::random(),
            13,
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: EntityUuid::random(),
            chested: true,
        );
        $donkey->storageInventory()->setStack(0, new ItemStack('minecraft:apple', 3));
        $declaration = new AnimalEquipmentSlotDeclaration(
            0,
            AnimalEquipmentSlotType::SADDLE,
            ['minecraft:saddle'],
        );
        $inventory = new HorseContainerInventory($donkey, [$declaration], $donkey);

        self::assertSame(16, $inventory->size());
        self::assertNull($inventory->stackAt(0));
        self::assertSame('minecraft:apple', $inventory->stackAt(1)?->identifier);
        $contents = $inventory->contents();
        $contents[0] = new ItemStack('minecraft:saddle', 1);
        $contents[1] = null;
        $contents[15] = new ItemStack('minecraft:stone', 4);
        self::assertTrue($inventory->replaceContents(array_values($contents), $inventory->revision()));
        self::assertTrue($donkey->isSaddled());
        self::assertNull($donkey->storageInventory()->stackAt(0));
        self::assertSame(4, $donkey->storageInventory()->stackAt(14)?->count);

        $withoutChest = new DonkeyEntity(
            EntityUuid::random(),
            14,
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: EntityUuid::random(),
        );
        self::assertSame(1, (new HorseContainerInventory($withoutChest, [$declaration], $withoutChest))->size());
    }

    public function testLlamaCarpetUsesItsDeclaredWireSlotAndLeadingCompositeIndex(): void
    {
        $llama = new LlamaEntity(
            EntityUuid::random(),
            15,
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: EntityUuid::random(),
            strength: 2,
            chested: true,
        );
        $declaration = new AnimalEquipmentSlotDeclaration(
            1,
            AnimalEquipmentSlotType::CARPET,
            ['minecraft:white_carpet', 'minecraft:lime_carpet'],
        );
        $inventory = new HorseContainerInventory($llama, [$declaration], $llama);

        self::assertSame(7, $inventory->size());
        $contents = $inventory->contents();
        $contents[0] = new ItemStack('minecraft:lime_carpet', 1);
        self::assertTrue($inventory->replaceContents($contents, $inventory->revision()));
        self::assertSame(WoolColor::LIME, $llama->getCarpetColor());
        self::assertSame('minecraft:lime_carpet', $inventory->stackAt(0)?->identifier);

        $revision = $inventory->revision();
        $llama->moveTo('world', new Position(1.0, 64.0, 0.0), 90.0, 0.0);
        $contents = $inventory->contents();
        $contents[0] = null;
        self::assertTrue($inventory->replaceContents($contents, $revision));
        self::assertNull($llama->getCarpetColor());
        self::assertNull($inventory->stackAt(0));
    }

    public function testMovementDoesNotInvalidateHorseEquipmentWindow(): void
    {
        $horse = new HorseEntity(EntityUuid::random(), 18, 'world', new Position(0.0, 64.0, 0.0));
        $inventory = new HorseContainerInventory($horse, $this->horseDeclarations(), null);
        $revision = $inventory->revision();

        $horse->moveTo('world', new Position(2.0, 65.0, -1.0), 135.0, 12.0);
        $horse->setMotion(new \Bedriox\Server\Entity\EntityMotion(0.1, 0.0, -0.1));

        self::assertSame($revision, $inventory->revision());
        self::assertTrue($inventory->replaceContents([
            null,
            new ItemStack('minecraft:iron_horse_armor', 1),
        ], $revision));
        self::assertSame('minecraft:iron_horse_armor', $horse->getHorseArmor()?->identifier);
    }

    public function testExternalEquipmentMutationInvalidatesAnOpenWindowRevision(): void
    {
        $horse = new HorseEntity(EntityUuid::random(), 16, 'world', new Position(0.0, 64.0, 0.0));
        $inventory = new HorseContainerInventory($horse, $this->horseDeclarations(), null);
        $revision = $inventory->revision();
        $horse->setSaddled(true);

        $this->expectException(ContainerRevisionMismatchException::class);
        $inventory->replaceContents([null, null], $revision);
    }

    public function testReplayedCompositeMutationCannotOverwriteNewerEquipmentOrStorage(): void
    {
        $donkey = new DonkeyEntity(
            EntityUuid::random(),
            17,
            'world',
            new Position(0.0, 64.0, 0.0),
            ownerUniqueId: EntityUuid::random(),
            chested: true,
        );
        $declaration = new AnimalEquipmentSlotDeclaration(
            0,
            AnimalEquipmentSlotType::SADDLE,
            ['minecraft:saddle'],
        );
        $inventory = new HorseContainerInventory($donkey, [$declaration], $donkey);
        $staleRevision = $inventory->revision();
        $first = $inventory->contents();
        $first[0] = new ItemStack('minecraft:saddle', 1);
        $first[1] = new ItemStack('minecraft:apple', 3);
        self::assertTrue($inventory->replaceContents($first, $staleRevision));
        $committedRevision = $inventory->revision();

        $replay = $inventory->contents();
        $replay[0] = null;
        $replay[1] = new ItemStack('minecraft:diamond', 64);
        try {
            $inventory->replaceContents($replay, $staleRevision);
            self::fail('A stale horse-container request overwrote newer authoritative state.');
        } catch (ContainerRevisionMismatchException) {
            self::assertSame($committedRevision, $inventory->revision());
            self::assertTrue($donkey->isSaddled());
            $stored = $donkey->storageInventory()->stackAt(0);
            self::assertNotNull($stored);
            self::assertSame('minecraft:apple', $stored->identifier);
            self::assertSame(3, $stored->count);
        }

        self::assertFalse($inventory->replaceContents($inventory->contents(), $committedRevision));
        self::assertSame($committedRevision, $inventory->revision());
    }

    /** @return non-empty-list<AnimalEquipmentSlotDeclaration> */
    private function horseDeclarations(): array
    {
        return [
            new AnimalEquipmentSlotDeclaration(
                0,
                AnimalEquipmentSlotType::SADDLE,
                ['minecraft:saddle'],
            ),
            new AnimalEquipmentSlotDeclaration(
                1,
                AnimalEquipmentSlotType::HORSE_ARMOR,
                HorseEntity::supportedHorseArmorIdentifiers(),
            ),
        ];
    }
}
