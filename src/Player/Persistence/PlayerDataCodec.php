<?php

declare(strict_types=1);

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataWriteException;
use Bedriox\Server\Player\Persistence\Exception\UnsupportedPlayerDataException;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use InvalidArgumentException;

/** Bounded schema-versioned player profile encoding with no session-local identifiers. */
final readonly class PlayerDataCodec
{
    public const int SCHEMA_VERSION = 4;
    public const int MAX_BYTES = 131_072;

    private const array REQUIRED_ROOT_TAGS = [
        'SchemaVersion',
        'Uuid',
        'Xuid',
        'LastKnownName',
        'FirstPlayed',
        'LastPlayed',
        'World',
        'Position',
        'Rotation',
        'GameMode',
        'Inventory',
        'SelectedHotbarSlot',
        'Health',
    ];

    public function __construct(private LittleEndianNbtCodec $nbt = new LittleEndianNbtCodec()) {}

    public function encode(PlayerBootstrap $player): string
    {
        try {
            self::validateIdentity($player->identity->uuid, $player->identity->xuid, $player->identity->displayName);
            foreach ($player->inventory->entries as $entry) {
                self::validateStack($entry->stack->identifier, $entry->stack->count, $entry->stack->damage);
            }
            if ($player->inventory->cursor !== null) {
                self::validateStack(
                    $player->inventory->cursor->identifier,
                    $player->inventory->cursor->count,
                    $player->inventory->cursor->damage,
                );
            }
        } catch (CorruptPlayerDataException $error) {
            throw new PlayerDataWriteException('Player profile contains a value which cannot be persisted.', previous: $error);
        }
        $inventory = [];
        foreach ($player->inventory->entries as $entry) {
            $inventory[] = LittleEndianNbtTag::compound([
                'Slot' => LittleEndianNbtTag::byte($entry->slot),
                'Identifier' => LittleEndianNbtTag::string($entry->stack->identifier),
                'Count' => LittleEndianNbtTag::byte($entry->stack->count),
                'Damage' => LittleEndianNbtTag::int($entry->stack->damage),
                'ItemNbt' => new LittleEndianNbtTag(LittleEndianNbtTag::BYTE_ARRAY, $entry->stack->nbt?->toBinary() ?? ''),
            ]);
        }
        $root = [
            'SchemaVersion' => LittleEndianNbtTag::int(self::SCHEMA_VERSION),
            'Uuid' => LittleEndianNbtTag::string($player->identity->uuid),
            'Xuid' => LittleEndianNbtTag::string($player->identity->xuid),
            'LastKnownName' => LittleEndianNbtTag::string($player->identity->displayName),
            'FirstPlayed' => LittleEndianNbtTag::long($player->firstPlayedAt),
            'LastPlayed' => LittleEndianNbtTag::long($player->lastPlayedAt),
            'World' => LittleEndianNbtTag::string($player->worldName),
            'Position' => LittleEndianNbtTag::list(LittleEndianNbtTag::DOUBLE, [
                new LittleEndianNbtTag(LittleEndianNbtTag::DOUBLE, $player->position->x),
                new LittleEndianNbtTag(LittleEndianNbtTag::DOUBLE, $player->position->y),
                new LittleEndianNbtTag(LittleEndianNbtTag::DOUBLE, $player->position->z),
            ]),
            'Rotation' => LittleEndianNbtTag::list(LittleEndianNbtTag::FLOAT, [
                LittleEndianNbtTag::float($player->yaw),
                LittleEndianNbtTag::float($player->pitch),
            ]),
            'GameMode' => LittleEndianNbtTag::string($player->gamemode),
            'Inventory' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $inventory),
            'SelectedHotbarSlot' => LittleEndianNbtTag::byte($player->inventory->selectedHotbarSlot),
            'Health' => LittleEndianNbtTag::float($player->health),
        ];
        if ($player->inventory->cursor !== null) {
            $root['Cursor'] = LittleEndianNbtTag::compound([
                'Identifier' => LittleEndianNbtTag::string($player->inventory->cursor->identifier),
                'Count' => LittleEndianNbtTag::byte($player->inventory->cursor->count),
                'Damage' => LittleEndianNbtTag::int($player->inventory->cursor->damage),
                'ItemNbt' => new LittleEndianNbtTag(LittleEndianNbtTag::BYTE_ARRAY, $player->inventory->cursor->nbt?->toBinary() ?? ''),
            ]);
        }
        try {
            $encoded = $this->nbt->encodeRootCompound($root);
        } catch (InvalidArgumentException $error) {
            throw new PlayerDataWriteException('Unable to encode the player profile.', previous: $error);
        }
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new PlayerDataWriteException('Encoded player profile exceeds the configured size limit.');
        }

        return $encoded;
    }

    public function decode(string $contents): PlayerBootstrap
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new CorruptPlayerDataException('Player profile exceeds the configured size limit.');
        }
        try {
            $root = $this->nbt->decodeRootCompound($contents);
        } catch (CorruptWorldDataException|InvalidArgumentException $error) {
            throw new CorruptPlayerDataException('Player profile contains malformed NBT.', previous: $error);
        }
        $schemaTag = $root['SchemaVersion'] ?? null;
        if (!$schemaTag instanceof LittleEndianNbtTag) {
            throw new CorruptPlayerDataException("Player profile is missing tag 'SchemaVersion'.");
        }
        $schemaVersion = self::integer($schemaTag, LittleEndianNbtTag::INT, 'SchemaVersion');
        if ($schemaVersion > self::SCHEMA_VERSION) {
            throw new UnsupportedPlayerDataException("Player profile schema version $schemaVersion is not supported.");
        }
        if ($schemaVersion < 1) {
            throw new CorruptPlayerDataException('Player profile schema version must be positive and supported.');
        }
        $required = $schemaVersion === 1
            ? array_filter(self::REQUIRED_ROOT_TAGS, static fn(string $name): bool => $name !== 'Health')
            : self::REQUIRED_ROOT_TAGS;
        $allowed = array_fill_keys([...$required, 'Cursor'], true);
        foreach ($root as $name => $_tag) {
            if (!isset($allowed[$name])) {
                throw new CorruptPlayerDataException("Player profile contains unknown tag '$name'.");
            }
        }
        foreach ($required as $name) {
            if (!isset($root[$name])) {
                throw new CorruptPlayerDataException("Player profile is missing tag '$name'.");
            }
        }
        try {
            $uuid = self::string($root['Uuid'], 'Uuid');
            $xuid = self::string($root['Xuid'], 'Xuid');
            $name = self::string($root['LastKnownName'], 'LastKnownName');
            self::validateIdentity($uuid, $xuid, $name);
            $position = self::numberList($root['Position'], LittleEndianNbtTag::DOUBLE, 3, 'Position');
            $rotation = self::numberList($root['Rotation'], LittleEndianNbtTag::FLOAT, 2, 'Rotation');
            $inventoryTag = self::tag($root['Inventory'], LittleEndianNbtTag::LIST, 'Inventory');
            if ($inventoryTag->listType !== LittleEndianNbtTag::COMPOUND || !is_array($inventoryTag->value)
                || count($inventoryTag->value) > PlayerInventory::SLOT_COUNT) {
                throw new CorruptPlayerDataException('Player inventory list is malformed or exceeds its slot limit.');
            }
            $entries = [];
            foreach ($inventoryTag->value as $item) {
                if (!$item instanceof LittleEndianNbtTag) {
                    throw new CorruptPlayerDataException('Player inventory contains an invalid entry.');
                }
                $itemTags = self::compound($item, 'Inventory');
                self::assertExactTags(
                    $itemTags,
                    self::stackTagNames($schemaVersion, true),
                    'inventory entry',
                );
                $entries[] = new PlayerInventoryEntry(
                    self::integer($itemTags['Slot'], LittleEndianNbtTag::BYTE, 'Inventory.Slot'),
                    self::stack($itemTags, 'Inventory', $schemaVersion),
                );
            }
            $cursor = null;
            if (isset($root['Cursor'])) {
                $cursorTags = self::compound($root['Cursor'], 'Cursor');
                self::assertExactTags(
                    $cursorTags,
                    self::stackTagNames($schemaVersion, false),
                    'cursor entry',
                );
                $cursor = self::stack($cursorTags, 'Cursor', $schemaVersion);
            }

            return new PlayerBootstrap(
                new PlayerIdentity($uuid, $name, $xuid),
                self::string($root['World'], 'World'),
                new Position($position[0], $position[1], $position[2]),
                $rotation[0],
                $rotation[1],
                new PlayerInventoryState(
                    $entries,
                    self::integer($root['SelectedHotbarSlot'], LittleEndianNbtTag::BYTE, 'SelectedHotbarSlot'),
                    $cursor,
                ),
                self::integer($root['FirstPlayed'], LittleEndianNbtTag::LONG, 'FirstPlayed'),
                self::integer($root['LastPlayed'], LittleEndianNbtTag::LONG, 'LastPlayed'),
                self::string($root['GameMode'], 'GameMode'),
                isset($root['Health']) ? self::floating($root['Health'], 'Health') : 20.0,
            );
        } catch (CorruptPlayerDataException $error) {
            throw $error;
        } catch (InvalidArgumentException $error) {
            throw new CorruptPlayerDataException('Player profile contains an invalid value.', previous: $error);
        }
    }

    /** @param array<string, LittleEndianNbtTag> $tags */
    private static function stack(array $tags, string $path, int $schemaVersion): PlayerInventoryStackState
    {
        $identifier = self::string($tags['Identifier'], "$path.Identifier");
        $count = self::integer($tags['Count'], LittleEndianNbtTag::BYTE, "$path.Count");
        $damage = $schemaVersion >= 3
            ? self::integer($tags['Damage'], LittleEndianNbtTag::INT, "$path.Damage")
            : 0;
        $itemNbt = null;
        if ($schemaVersion >= 4) {
            $bytes = self::tag($tags['ItemNbt'], LittleEndianNbtTag::BYTE_ARRAY, "$path.ItemNbt")->value;
            if (!is_string($bytes)) {
                throw new CorruptPlayerDataException("Player inventory $path item NBT is invalid.");
            }
            $itemNbt = $bytes === '' ? null : ItemNbt::fromBinary($bytes);
        }
        self::validateStack($identifier, $count, $damage);

        return new PlayerInventoryStackState($identifier, $count, $damage, $itemNbt);
    }

    private static function tag(LittleEndianNbtTag $tag, int $type, string $name): LittleEndianNbtTag
    {
        if ($tag->type !== $type) {
            throw new CorruptPlayerDataException("Player profile tag '$name' has the wrong type.");
        }

        return $tag;
    }

    /** @return array<string, LittleEndianNbtTag> */
    private static function compound(LittleEndianNbtTag $tag, string $name): array
    {
        $value = self::tag($tag, LittleEndianNbtTag::COMPOUND, $name)->value;
        if (!is_array($value)) {
            throw new CorruptPlayerDataException("Player profile tag '$name' is not a compound.");
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || !$entry instanceof LittleEndianNbtTag) {
                throw new CorruptPlayerDataException("Player profile tag '$name' contains an invalid compound entry.");
            }
            $result[$key] = $entry;
        }

        return $result;
    }

    private static function integer(LittleEndianNbtTag $tag, int $type, string $name): int
    {
        $value = self::tag($tag, $type, $name)->value;
        if (!is_int($value)) {
            throw new CorruptPlayerDataException("Player profile tag '$name' is not an integer.");
        }

        return $value;
    }

    private static function string(LittleEndianNbtTag $tag, string $name): string
    {
        $value = self::tag($tag, LittleEndianNbtTag::STRING, $name)->value;
        if (!is_string($value)) {
            throw new CorruptPlayerDataException("Player profile tag '$name' is not a string.");
        }

        return $value;
    }

    private static function floating(LittleEndianNbtTag $tag, string $name): float
    {
        $value = self::tag($tag, LittleEndianNbtTag::FLOAT, $name)->value;
        if (!is_float($value) || !is_finite($value)) {
            throw new CorruptPlayerDataException("Player profile tag '$name' is not a finite float.");
        }

        return $value;
    }

    /** @return list<float> */
    private static function numberList(LittleEndianNbtTag $tag, int $type, int $count, string $name): array
    {
        self::tag($tag, LittleEndianNbtTag::LIST, $name);
        if ($tag->listType !== $type || !is_array($tag->value) || count($tag->value) !== $count) {
            throw new CorruptPlayerDataException("Player profile tag '$name' has an invalid list shape.");
        }
        $values = [];
        foreach ($tag->value as $item) {
            if (!$item instanceof LittleEndianNbtTag || $item->type !== $type || !is_float($item->value)) {
                throw new CorruptPlayerDataException("Player profile tag '$name' contains an invalid number.");
            }
            $values[] = $item->value;
        }

        return $values;
    }

    /** @param array<string, LittleEndianNbtTag> $tags
     *  @param list<string> $expected
     */
    private static function assertExactTags(array $tags, array $expected, string $name): void
    {
        $actual = array_keys($tags);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new CorruptPlayerDataException("Player $name has unexpected or missing tags.");
        }
    }

    private static function validateIdentity(string $uuid, string $xuid, string $name): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new CorruptPlayerDataException('Player UUID is not canonical lowercase text.');
        }
        if ($xuid !== '' && (strlen($xuid) > 32 || preg_match('/^[0-9]+$/D', $xuid) !== 1)) {
            throw new CorruptPlayerDataException('Player XUID is invalid.');
        }
        if ($name === '' || strlen($name) > 64 || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new CorruptPlayerDataException('Player name is invalid.');
        }
    }

    /** @return list<string> */
    private static function stackTagNames(int $schemaVersion, bool $withSlot): array
    {
        $tags = $withSlot ? ['Slot', 'Identifier', 'Count'] : ['Identifier', 'Count'];
        if ($schemaVersion >= 3) {
            $tags[] = 'Damage';
        }
        if ($schemaVersion >= 4) {
            $tags[] = 'ItemNbt';
        }

        return $tags;
    }

    private static function validateStack(string $identifier, int $count, int $damage): void
    {
        if (strlen($identifier) > 256 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || $count < 1 || $count > 64
            || $damage < 0 || $damage > PlayerInventoryStackState::MAX_DAMAGE) {
            throw new CorruptPlayerDataException('Player inventory stack is invalid.');
        }
    }
}
