<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player\Persistence;

use Bedriox\Api\Inventory\ItemNbt;
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
    public function testRoundTripsCompleteSessionIndependentProfile(): void
    {
        $profile = self::profile();
        $codec = new PlayerDataCodec();

        $decoded = $codec->decode($codec->encode($profile));

        self::assertEquals($profile, $decoded);
        self::assertSame([0, 17], array_map(static fn(PlayerInventoryEntry $entry): int => $entry->slot, $decoded->inventory->entries));
        self::assertSame('minecraft:grass_block', $decoded->inventory->cursor?->identifier);
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
            new PlayerInventoryEntry(3, new PlayerInventoryStackState('minecraft:diamond_pickaxe', 1, 713, ItemNbt::empty()->withString('bedriox:feature', 'saved'))),
            new PlayerInventoryEntry(9, new PlayerInventoryStackState('example:custom_item', 12, 4)),
        ], 3, new PlayerInventoryStackState('minecraft:iron_shovel', 1, 122)));

        $decoded = (new PlayerDataCodec())->decode((new PlayerDataCodec())->encode($profile));

        self::assertEquals($profile, $decoded);
        self::assertSame(713, $decoded->inventory->entries[0]->stack->damage);
        self::assertSame('saved', $decoded->inventory->entries[0]->stack->nbt?->string('bedriox:feature'));
        self::assertSame('example:custom_item', $decoded->inventory->entries[1]->stack->identifier);
        self::assertSame(122, $decoded->inventory->cursor?->damage);
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
        unset($legacy['Health']);
        $legacy = self::withoutDamageTags($legacy);
        self::assertSame(
            20.0,
            $codec->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy))->health,
        );
    }

    public function testSchemaOneAndTwoInventoryStacksMigrateWithZeroDamage(): void
    {
        $codec = new PlayerDataCodec();
        foreach ([1, 2] as $schema) {
            $legacy = self::withoutDamageTags(self::root());
            $legacy['SchemaVersion'] = LittleEndianNbtTag::int($schema);
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
            ], 1, new PlayerInventoryStackState('minecraft:grass_block', 3)),
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

    /**
     * @param array<string, LittleEndianNbtTag> $root
     * @return array<string, LittleEndianNbtTag>
     */
    private static function withoutDamageTags(array $root): array
    {
        $inventory = $root['Inventory'];
        self::assertIsArray($inventory->value);
        $entries = [];
        foreach ($inventory->value as $entry) {
            self::assertInstanceOf(LittleEndianNbtTag::class, $entry);
            $entries[] = self::withoutDamageTag($entry);
        }
        $root['Inventory'] = LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $entries);
        if (isset($root['Cursor'])) {
            $root['Cursor'] = self::withoutDamageTag($root['Cursor']);
        }

        return $root;
    }

    private static function withoutDamageTag(LittleEndianNbtTag $compound): LittleEndianNbtTag
    {
        self::assertIsArray($compound->value);
        $tags = [];
        foreach ($compound->value as $name => $tag) {
            self::assertIsString($name);
            self::assertInstanceOf(LittleEndianNbtTag::class, $tag);
            if ($name !== 'Damage' && $name !== 'ItemNbt') {
                $tags[$name] = $tag;
            }
        }

        return LittleEndianNbtTag::compound($tags);
    }
}
