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

namespace Bedriox\Server\Tests\Player\Persistence;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Effect\ActiveEffectCollection;
use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\UnsupportedPlayerDataException;
use Bedriox\Server\Player\Persistence\PlayerDataCodec;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use PHPUnit\Framework\TestCase;

final class PlayerDataCodecTest extends TestCase
{
    public function testPersistsActiveEffectsWithoutWireIdentifiers(): void
    {
        $profile = self::profile();
        $profile = new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            $profile->inventory,
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
            $profile->gamemode,
            $profile->health,
            $profile->food,
            $profile->saturation,
            $profile->exhaustion,
            [new EffectInstance(EffectType::SPEED, 600, 1, false, true, false)],
        );

        $decoded = (new PlayerDataCodec())->decode((new PlayerDataCodec())->encode($profile));

        self::assertEquals($profile->effects, $decoded->effects);
    }

    public function testPersistsBoundedHiddenEffectFallbackState(): void
    {
        $effects = new ActiveEffectCollection();
        $effects->add(new EffectInstance(EffectType::SPEED, 200));
        $effects->add(new EffectInstance(EffectType::SPEED, 40, 1));
        $profile = self::profile();
        $profile = new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            $profile->inventory,
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
            effects: array_values($effects->snapshot()),
            effectPersistenceState: $effects->persistenceState(),
        );

        $decoded = (new PlayerDataCodec())->decode((new PlayerDataCodec())->encode($profile));
        self::assertNotNull($decoded->effectPersistenceState);
        $restored = new ActiveEffectCollection();
        $restored->restorePersistenceState($decoded->effectPersistenceState);
        $restored->tick(40, static function (): void {});
        $promoted = $restored->get(EffectType::SPEED);
        self::assertNotNull($promoted);
        self::assertSame(160, $promoted->durationTicks);
        self::assertSame(0, $promoted->amplifier);
    }

    public function testRoundTripsCompleteSessionIndependentProfile(): void
    {
        $profile = self::profile();
        $codec = new PlayerDataCodec();

        $decoded = $codec->decode($codec->encode($profile));

        self::assertEquals($profile, $decoded);
        self::assertSame([0, 17], array_map(static fn(PlayerInventoryEntry $entry): int => $entry->slot, $decoded->inventory->entries));
        self::assertSame('minecraft:grass_block', $decoded->inventory->cursor?->identifier);
        self::assertSame(
            ['minecraft:diamond_helmet', 'minecraft:diamond_chestplate', 'minecraft:diamond_leggings', 'minecraft:diamond_boots'],
            array_map(static fn(PlayerInventoryEntry $entry): string => $entry->stack->identifier, $decoded->inventory->armor),
        );
        self::assertSame('minecraft:shield', $decoded->inventory->offhand?->identifier);
        self::assertSame(
            ['minecraft:ender_pearl', 'minecraft:diamond'],
            array_map(
                static fn(PlayerInventoryEntry $entry): string => $entry->stack->identifier,
                $decoded->inventory->enderChest,
            ),
        );
    }

    public function testPersistsAnIronTool(): void
    {
        $profile = self::profile();
        $withTool = new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:iron_pickaxe', 1)),
            ], 0),
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
        );

        $codec = new PlayerDataCodec();
        self::assertEquals($withTool, $codec->decode($codec->encode($withTool)));
    }

    public function testRoundTripsArbitraryItemsAndDamageWithoutSessionNetworkIds(): void
    {
        $profile = self::profileWithInventory(new PlayerInventoryState([
            new PlayerInventoryEntry(3, new PlayerInventoryStackState('minecraft:diamond_pickaxe', 1, 713, ItemNbt::empty()->withString('bedriox:feature', 'saved'), 12)),
            new PlayerInventoryEntry(9, new PlayerInventoryStackState('example:custom_item', 12, 4, auxValue: 305)),
        ], 3, new PlayerInventoryStackState('minecraft:iron_shovel', 1, 122, auxValue: 32_767)));

        $decoded = (new PlayerDataCodec())->decode((new PlayerDataCodec())->encode($profile));

        self::assertEquals($profile, $decoded);
        self::assertSame(713, $decoded->inventory->entries[0]->stack->damage);
        self::assertSame('saved', $decoded->inventory->entries[0]->stack->nbt?->string('bedriox:feature'));
        self::assertSame('example:custom_item', $decoded->inventory->entries[1]->stack->identifier);
        self::assertSame(305, $decoded->inventory->entries[1]->stack->auxValue);
        self::assertSame(122, $decoded->inventory->cursor?->damage);
        self::assertSame(32_767, $decoded->inventory->cursor->auxValue);
    }

    public function testPersistsHealthAndMigratesSchemaOneProfilesAtFullHealth(): void
    {
        $profile = self::profile();
        $profile = new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            $profile->inventory,
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
            $profile->gamemode,
            7.5,
        );
        $codec = new PlayerDataCodec();
        self::assertSame(7.5, $codec->decode($codec->encode($profile))->health);

        $legacy = self::root();
        $legacy['SchemaVersion'] = LittleEndianNbtTag::int(1);
        self::removeSchemaSixTags($legacy);
        unset($legacy['Health']);
        $legacy = self::withoutDamageTags($legacy);
        self::assertSame(
            20.0,
            $codec->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy))->health,
        );
    }

    public function testPersistsEffectDerivedVitalsAndEnvironmentState(): void
    {
        $profile = self::profile();
        $effect = new EffectInstance(EffectType::ABSORPTION, 200, 1);
        $profile = new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            $profile->inventory,
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
            $profile->gamemode,
            $profile->health,
            effects: [$effect],
            absorption: 6.0,
            airTicks: 75,
            fireTicks: 40,
        );

        $decoded = (new PlayerDataCodec())->decode((new PlayerDataCodec())->encode($profile));

        self::assertSame(6.0, $decoded->absorption);
        self::assertSame(75, $decoded->airTicks);
        self::assertSame(40, $decoded->fireTicks);
    }

    public function testPersistsNutritionAndMigratesSchemaFiveProfilesToEmptyEquipmentDefaults(): void
    {
        $profile = self::profile();
        $profile = new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            $profile->inventory,
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
            $profile->gamemode,
            $profile->health,
            13.0,
            4.5,
            2.25,
        );
        $codec = new PlayerDataCodec();
        $decoded = $codec->decode($codec->encode($profile));
        self::assertSame(13.0, $decoded->food);
        self::assertSame(4.5, $decoded->saturation);
        self::assertSame(2.25, $decoded->exhaustion);

        $legacy = self::root();
        $legacy['SchemaVersion'] = LittleEndianNbtTag::int(5);
        self::removeSchemaSixTags($legacy);
        $decodedLegacy = $codec->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy));
        self::assertSame(20.0, $decodedLegacy->food);
        self::assertSame(20.0, $decodedLegacy->saturation);
        self::assertSame(0.0, $decodedLegacy->exhaustion);
        self::assertSame([], $decodedLegacy->inventory->armor);
        self::assertNull($decodedLegacy->inventory->offhand);
    }

    public function testSchemaSixProfilesMigrateToAnEmptyEnderChest(): void
    {
        $legacy = self::root();
        $legacy['SchemaVersion'] = LittleEndianNbtTag::int(6);
        unset($legacy['EnderChest'], $legacy['Effects'], $legacy['Absorption'], $legacy['AirTicks'], $legacy['FireTicks']);

        $decoded = (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy));

        self::assertSame([], $decoded->inventory->enderChest);
    }

    public function testSchemaOneAndTwoInventoryStacksMigrateWithZeroDamage(): void
    {
        $codec = new PlayerDataCodec();
        foreach ([1, 2] as $schema) {
            $legacy = self::withoutDamageTags(self::root());
            $legacy['SchemaVersion'] = LittleEndianNbtTag::int($schema);
            self::removeSchemaSixTags($legacy);
            if ($schema === 1) {
                unset($legacy['Health']);
            }

            $decoded = $codec->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy));
            foreach ($decoded->inventory->entries as $entry) {
                self::assertSame(0, $entry->stack->damage);
            }
            self::assertSame(0, $decoded->inventory->cursor?->damage);
        }
    }

    public function testSchemasOneThroughFourInventoryStacksMigrateWithZeroAux(): void
    {
        $codec = new PlayerDataCodec();
        foreach ([1, 2, 3, 4] as $schema) {
            $legacy = self::withoutStackTags(self::root(), match ($schema) {
                1, 2 => ['Damage', 'ItemNbt', 'Aux'],
                3 => ['ItemNbt', 'Aux'],
                4 => ['Aux'],
            });
            $legacy['SchemaVersion'] = LittleEndianNbtTag::int($schema);
            self::removeSchemaSixTags($legacy);
            if ($schema === 1) {
                unset($legacy['Health']);
            }

            $decoded = $codec->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy));
            foreach ($decoded->inventory->entries as $entry) {
                self::assertSame(0, $entry->stack->auxValue);
            }
            self::assertSame(0, $decoded->inventory->cursor?->auxValue);
        }
    }

    public function testRejectsAuxOutsideThePersistedRange(): void
    {
        $root = self::root();
        $entries = self::listValues($root['Inventory']);
        $tags = self::compoundValues($entries[0]);
        $tags['Aux'] = LittleEndianNbtTag::int(PlayerInventoryStackState::MAX_AUX_VALUE + 1);
        $entries[0] = LittleEndianNbtTag::compound($tags);
        $root['Inventory'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $entries);

        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testSchemaFiveRequiresTheExactAuxTag(): void
    {
        $root = self::withoutStackTags(self::root(), ['Aux']);

        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testRejectsHealthOutsideTheAuthoritativeRange(): void
    {
        $root = self::root();
        $root['Health'] = LittleEndianNbtTag::float(20.5);

        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testRejectsFutureSchemaWithoutTreatingItAsMissingData(): void
    {
        $root = self::root();
        $root['SchemaVersion'] = LittleEndianNbtTag::int(PlayerDataCodec::SCHEMA_VERSION + 1);

        $this->expectException(UnsupportedPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testRejectsMalformedTrailingAndOversizedProfiles(): void
    {
        $valid = (new PlayerDataCodec())->encode(self::profile());
        $rejected = 0;
        foreach ([substr($valid, 0, -1), $valid . "\0", str_repeat("\0", PlayerDataCodec::MAX_BYTES + 1)] as $contents) {
            try {
                (new PlayerDataCodec())->decode($contents);
                self::fail('Malformed player profile was accepted.');
            } catch (CorruptPlayerDataException) {
                ++$rejected;
            }
        }

        self::assertSame(3, $rejected);
    }

    public function testRejectsUnknownRootTagWrongTypesAndInvalidIdentity(): void
    {
        $unexpected = self::root();
        $unexpected['Unexpected'] = LittleEndianNbtTag::byte(1);
        $wrongPosition = self::root();
        $wrongPosition['Position'] = LittleEndianNbtTag::string('not-a-list');
        $unsafeUuid = self::root();
        $unsafeUuid['Uuid'] = LittleEndianNbtTag::string('../another-player');
        $missingWorld = self::root();
        unset($missingWorld['World']);
        $rejected = 0;
        foreach ([$unexpected, $wrongPosition, $unsafeUuid, $missingWorld] as $root) {
            try {
                (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
                self::fail('Invalid player profile structure was accepted.');
            } catch (CorruptPlayerDataException) {
                ++$rejected;
            }
        }

        self::assertSame(4, $rejected);
    }

    public function testRejectsDuplicateAndOutOfRangeInventoryEntries(): void
    {
        $root = self::root();
        $entry = LittleEndianNbtTag::compound([
            'Slot' => LittleEndianNbtTag::byte(0),
            'Identifier' => LittleEndianNbtTag::string('minecraft:grass_block'),
            'Count' => LittleEndianNbtTag::byte(1),
        ]);
        $root['Inventory'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, [$entry, $entry]);
        try {
            (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
            self::fail('Duplicate inventory slots were accepted.');
        } catch (CorruptPlayerDataException) {
        }

        $root = self::root();
        $root['Inventory'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, [
            LittleEndianNbtTag::compound([
                'Slot' => LittleEndianNbtTag::byte(36),
                'Identifier' => LittleEndianNbtTag::string('minecraft:grass_block'),
                'Count' => LittleEndianNbtTag::byte(1),
            ]),
        ]);
        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testRejectsDuplicateAndOutOfRangeArmorEntries(): void
    {
        $root = self::root();
        $armor = self::listValues($root['Armor']);
        $root['Armor'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, [$armor[0], $armor[0]]);
        try {
            (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
            self::fail('Duplicate armor slots were accepted.');
        } catch (CorruptPlayerDataException) {
        }

        $root = self::root();
        $armor = self::listValues($root['Armor']);
        $tags = self::compoundValues($armor[0]);
        $tags['Slot'] = LittleEndianNbtTag::byte(4);
        $armor[0] = LittleEndianNbtTag::compound($tags);
        $root['Armor'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $armor);

        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testRejectsStackedArmorInPersistedEquipment(): void
    {
        $root = self::root();
        $armor = self::listValues($root['Armor']);
        $tags = self::compoundValues($armor[0]);
        $tags['Count'] = LittleEndianNbtTag::byte(2);
        $armor[0] = LittleEndianNbtTag::compound($tags);
        $root['Armor'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $armor);

        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    public function testRejectsDuplicateAndOutOfRangeEnderChestEntries(): void
    {
        $root = self::root();
        $enderChest = self::listValues($root['EnderChest']);
        $root['EnderChest'] = LittleEndianNbtTag::list(
            LittleEndianNbtTag::COMPOUND,
            [$enderChest[0], $enderChest[0]],
        );
        try {
            (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
            self::fail('Duplicate Ender Chest slots were accepted.');
        } catch (CorruptPlayerDataException) {
        }

        $root = self::root();
        $enderChest = self::listValues($root['EnderChest']);
        $tags = self::compoundValues($enderChest[0]);
        $tags['Slot'] = LittleEndianNbtTag::byte(27);
        $root['EnderChest'] = LittleEndianNbtTag::list(
            LittleEndianNbtTag::COMPOUND,
            [LittleEndianNbtTag::compound($tags)],
        );

        $this->expectException(CorruptPlayerDataException::class);
        (new PlayerDataCodec())->decode((new LittleEndianNbtCodec())->encodeRootCompound($root));
    }

    /** @return array<string, LittleEndianNbtTag> */
    private static function root(): array
    {
        return (new LittleEndianNbtCodec())->decodeRootCompound((new PlayerDataCodec())->encode(self::profile()));
    }

    private static function profile(string $name = 'Player'): PlayerBootstrap
    {
        return new PlayerBootstrap(
            new PlayerIdentity('12345678-1234-5678-9abc-123456789abc', $name, '123456789'),
            'world',
            new Position(-12.25, 70.5, 31.75),
            180.0,
            -12.5,
            new PlayerInventoryState([
                new PlayerInventoryEntry(17, new PlayerInventoryStackState('minecraft:grass_block', 32)),
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:grass_block', 64)),
            ], 1, new PlayerInventoryStackState('minecraft:grass_block', 3), [
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond_helmet', 1)),
                new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:diamond_chestplate', 1)),
                new PlayerInventoryEntry(2, new PlayerInventoryStackState('minecraft:diamond_leggings', 1)),
                new PlayerInventoryEntry(3, new PlayerInventoryStackState('minecraft:diamond_boots', 1)),
            ], new PlayerInventoryStackState('minecraft:shield', 1), [
                new PlayerInventoryEntry(3, new PlayerInventoryStackState('minecraft:ender_pearl', 12)),
                new PlayerInventoryEntry(24, new PlayerInventoryStackState('minecraft:diamond', 5)),
            ]),
            1_700_000_000_000,
            1_700_000_001_000,
        );
    }

    private static function profileWithInventory(PlayerInventoryState $inventory): PlayerBootstrap
    {
        $profile = self::profile();

        return new PlayerBootstrap(
            $profile->identity,
            $profile->worldName,
            $profile->position,
            $profile->yaw,
            $profile->pitch,
            $inventory,
            $profile->firstPlayedAt,
            $profile->lastPlayedAt,
            $profile->gamemode,
            $profile->health,
        );
    }

    /** @param array<string, LittleEndianNbtTag> $root */
    private static function removeSchemaSixTags(array &$root): void
    {
        unset(
            $root['Armor'],
            $root['Offhand'],
            $root['EnderChest'],
            $root['FoodLevel'],
            $root['Saturation'],
            $root['Exhaustion'],
            $root['Effects'],
            $root['Absorption'],
            $root['AirTicks'],
            $root['FireTicks'],
        );
    }

    /**
     * @param array<string, LittleEndianNbtTag> $root
     * @return array<string, LittleEndianNbtTag>
     */
    private static function withoutDamageTags(array $root): array
    {
        return self::withoutStackTags($root, ['Damage', 'ItemNbt', 'Aux']);
    }

    /**
     * @param array<string, LittleEndianNbtTag> $root
     * @param list<string> $removed
     * @return array<string, LittleEndianNbtTag>
     */
    private static function withoutStackTags(array $root, array $removed): array
    {
        $inventory = $root['Inventory'];
        self::assertIsArray($inventory->value);
        $entries = [];
        foreach ($inventory->value as $entry) {
            self::assertInstanceOf(LittleEndianNbtTag::class, $entry);
            $entries[] = self::withoutStackTagNames($entry, $removed);
        }
        $root['Inventory'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $entries);
        if (isset($root['Cursor'])) {
            $root['Cursor'] = self::withoutStackTagNames($root['Cursor'], $removed);
        }
        if (isset($root['Armor'])) {
            $armor = self::listValues($root['Armor']);
            foreach ($armor as $index => $entry) {
                $armor[$index] = self::withoutStackTagNames($entry, $removed);
            }
            $root['Armor'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $armor);
        }
        if (isset($root['Offhand'])) {
            $root['Offhand'] = self::withoutStackTagNames($root['Offhand'], $removed);
        }
        if (isset($root['EnderChest'])) {
            $enderChest = self::listValues($root['EnderChest']);
            foreach ($enderChest as $index => $entry) {
                $enderChest[$index] = self::withoutStackTagNames($entry, $removed);
            }
            $root['EnderChest'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $enderChest);
        }

        return $root;
    }

    /** @param list<string> $removed */
    private static function withoutStackTagNames(LittleEndianNbtTag $compound, array $removed): LittleEndianNbtTag
    {
        self::assertIsArray($compound->value);
        $tags = [];
        foreach ($compound->value as $name => $tag) {
            self::assertIsString($name);
            self::assertInstanceOf(LittleEndianNbtTag::class, $tag);
            if (!in_array($name, $removed, true)) {
                $tags[$name] = $tag;
            }
        }

        return LittleEndianNbtTag::compound($tags);
    }

    /** @return list<LittleEndianNbtTag> */
    private static function listValues(LittleEndianNbtTag $tag): array
    {
        self::assertSame(LittleEndianNbtTag::LIST, $tag->type);
        self::assertIsArray($tag->value);
        $values = [];
        foreach ($tag->value as $value) {
            self::assertInstanceOf(LittleEndianNbtTag::class, $value);
            $values[] = $value;
        }

        return $values;
    }

    /** @return array<string, LittleEndianNbtTag> */
    private static function compoundValues(LittleEndianNbtTag $tag): array
    {
        self::assertSame(LittleEndianNbtTag::COMPOUND, $tag->type);
        self::assertIsArray($tag->value);
        $values = [];
        foreach ($tag->value as $name => $value) {
            self::assertIsString($name);
            self::assertInstanceOf(LittleEndianNbtTag::class, $value);
            $values[$name] = $value;
        }

        return $values;
    }
}
