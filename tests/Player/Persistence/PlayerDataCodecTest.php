<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player\Persistence;

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
        self::assertSame(
            20.0,
            $codec->decode((new LittleEndianNbtCodec())->encodeRootCompound($legacy))->health,
        );
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
}
