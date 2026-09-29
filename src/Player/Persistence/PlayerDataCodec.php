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

namespace Bedriox\Server\Player\Persistence;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\Effect\ActiveEffectPersistenceState;
use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataWriteException;
use Bedriox\Server\Player\Persistence\Exception\UnsupportedPlayerDataException;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Player\PlayerVitals;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use InvalidArgumentException;

/** Bounded schema-versioned player profile encoding with no session-local identifiers. */
final readonly class PlayerDataCodec
{
    public const int SCHEMA_VERSION = 10;
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
        'Armor',
        'EnderChest',
        'SelectedHotbarSlot',
        'Health',
        'FoodLevel',
        'Saturation',
        'Exhaustion',
        'Effects',
        'Absorption',
        'AirTicks',
        'FireTicks',
    ];

    public function __construct(private LittleEndianNbtCodec $nbt = new LittleEndianNbtCodec()) {}

    public function encode(PlayerBootstrap $player): string
    {
        try {
            self::validateIdentity($player->identity->uuid, $player->identity->xuid, $player->identity->displayName);
            foreach ($player->inventory->entries as $entry) {
                self::validateStack(
                    $entry->stack->identifier,
                    $entry->stack->count,
                    $entry->stack->damage,
                    $entry->stack->auxValue,
                );
            }
            foreach ($player->inventory->armor as $entry) {
                self::validateStack(
                    $entry->stack->identifier,
                    $entry->stack->count,
                    $entry->stack->damage,
                    $entry->stack->auxValue,
                );
                if ($entry->stack->count !== 1) {
                    throw new CorruptPlayerDataException('Persisted armor stacks must contain exactly one item.');
                }
            }
            foreach ($player->inventory->enderChest as $entry) {
                self::validateStack(
                    $entry->stack->identifier,
                    $entry->stack->count,
                    $entry->stack->damage,
                    $entry->stack->auxValue,
                );
            }
            if ($player->inventory->cursor !== null) {
                self::validateStack(
                    $player->inventory->cursor->identifier,
                    $player->inventory->cursor->count,
                    $player->inventory->cursor->damage,
                    $player->inventory->cursor->auxValue,
                );
            }
            if ($player->inventory->offhand !== null) {
                self::validateStack(
                    $player->inventory->offhand->identifier,
                    $player->inventory->offhand->count,
                    $player->inventory->offhand->damage,
                    $player->inventory->offhand->auxValue,
                );
            }
        } catch (CorruptPlayerDataException $error) {
            throw new PlayerDataWriteException('Player profile contains a value which cannot be persisted.', previous: $error);
        }
        $inventory = [];
        foreach ($player->inventory->entries as $entry) {
            $inventory[] = self::encodedStack($entry->stack, $entry->slot);
        }
        $armor = [];
        foreach ($player->inventory->armor as $entry) {
            $armor[] = self::encodedStack($entry->stack, $entry->slot);
        }
        $enderChest = [];
        foreach ($player->inventory->enderChest as $entry) {
            $enderChest[] = self::encodedStack($entry->stack, $entry->slot);
        }
        $effectState = $player->effectPersistenceState ?? new ActiveEffectPersistenceState(array_combine(
            array_map(static fn(EffectInstance $effect): string => $effect->type->value, $player->effects),
            $player->effects,
        ) ?: []);
        $encodedEffects = [];
        foreach ($effectState->active as $key => $effect) {
            $encodedEffects[] = self::encodedEffect(
                $effect,
                true,
                0,
                $effectState->infiniteElapsedTicks[$key] ?? 0,
            );
            foreach ($effectState->hidden[$key] ?? [] as $order => $fallback) {
                $encodedEffects[] = self::encodedEffect($fallback, false, $order, 0);
            }
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
            'Armor' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $armor),
            'EnderChest' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $enderChest),
            'SelectedHotbarSlot' => LittleEndianNbtTag::byte($player->inventory->selectedHotbarSlot),
            'Health' => LittleEndianNbtTag::float($player->health),
            'FoodLevel' => LittleEndianNbtTag::float($player->food),
            'Saturation' => LittleEndianNbtTag::float($player->saturation),
            'Exhaustion' => LittleEndianNbtTag::float($player->exhaustion),
            'Effects' => LittleEndianNbtTag::list(
                LittleEndianNbtTag::COMPOUND,
                $encodedEffects,
            ),
            'Absorption' => LittleEndianNbtTag::float($player->absorption),
            'AirTicks' => LittleEndianNbtTag::int($player->airTicks),
            'FireTicks' => LittleEndianNbtTag::int($player->fireTicks),
        ];
        if ($player->inventory->cursor !== null) {
            $root['Cursor'] = self::encodedStack($player->inventory->cursor);
        }
        if ($player->inventory->offhand !== null) {
            $root['Offhand'] = self::encodedStack($player->inventory->offhand);
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
        $required = array_filter(
            self::REQUIRED_ROOT_TAGS,
            static fn(string $name): bool => !($schemaVersion === 1 && $name === 'Health')
                && !($schemaVersion < 6 && in_array(
                    $name,
                    ['Armor', 'FoodLevel', 'Saturation', 'Exhaustion'],
                    true,
                ))
                && !($schemaVersion < 7 && $name === 'EnderChest')
                && !($schemaVersion < 8 && $name === 'Effects')
                && !($schemaVersion < 9 && in_array($name, ['Absorption', 'AirTicks', 'FireTicks'], true)),
        );
        $allowed = array_fill_keys([
            ...$required,
            'Cursor',
            ...($schemaVersion >= 6 ? ['Offhand'] : []),
        ], true);
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
            $armor = [];
            if ($schemaVersion >= 6) {
                $armorTag = self::tag($root['Armor'], LittleEndianNbtTag::LIST, 'Armor');
                if ($armorTag->listType !== LittleEndianNbtTag::COMPOUND || !is_array($armorTag->value)
                    || count($armorTag->value) > PlayerInventory::ARMOR_SLOT_COUNT) {
                    throw new CorruptPlayerDataException('Player armor list is malformed or exceeds its slot limit.');
                }
                foreach ($armorTag->value as $item) {
                    if (!$item instanceof LittleEndianNbtTag) {
                        throw new CorruptPlayerDataException('Player armor contains an invalid entry.');
                    }
                    $itemTags = self::compound($item, 'Armor');
                    self::assertExactTags($itemTags, self::stackTagNames($schemaVersion, true), 'armor entry');
                    $stack = self::stack($itemTags, 'Armor', $schemaVersion);
                    if ($stack->count !== 1) {
                        throw new CorruptPlayerDataException('Player armor stack count must be one.');
                    }
                    $armor[] = new PlayerInventoryEntry(
                        self::integer($itemTags['Slot'], LittleEndianNbtTag::BYTE, 'Armor.Slot'),
                        $stack,
                    );
                }
            }
            $offhand = null;
            if (isset($root['Offhand'])) {
                $offhandTags = self::compound($root['Offhand'], 'Offhand');
                self::assertExactTags(
                    $offhandTags,
                    self::stackTagNames($schemaVersion, false),
                    'offhand entry',
                );
                $offhand = self::stack($offhandTags, 'Offhand', $schemaVersion);
            }
            $enderChest = [];
            if ($schemaVersion >= 7) {
                $enderChestTag = self::tag($root['EnderChest'], LittleEndianNbtTag::LIST, 'EnderChest');
                if ($enderChestTag->listType !== LittleEndianNbtTag::COMPOUND || !is_array($enderChestTag->value)
                    || count($enderChestTag->value) > PlayerInventory::ENDER_CHEST_SLOT_COUNT) {
                    throw new CorruptPlayerDataException(
                        'Player Ender Chest list is malformed or exceeds its slot limit.',
                    );
                }
                foreach ($enderChestTag->value as $item) {
                    if (!$item instanceof LittleEndianNbtTag) {
                        throw new CorruptPlayerDataException('Player Ender Chest contains an invalid entry.');
                    }
                    $itemTags = self::compound($item, 'EnderChest');
                    self::assertExactTags(
                        $itemTags,
                        self::stackTagNames($schemaVersion, true),
                        'Ender Chest entry',
                    );
                    $enderChest[] = new PlayerInventoryEntry(
                        self::integer($itemTags['Slot'], LittleEndianNbtTag::BYTE, 'EnderChest.Slot'),
                        self::stack($itemTags, 'EnderChest', $schemaVersion),
                    );
                }
            }
            $effects = [];
            $effectPersistenceState = null;
            if ($schemaVersion >= 8) {
                $effectsTag = self::tag($root['Effects'], LittleEndianNbtTag::LIST, 'Effects');
                if ($effectsTag->listType !== LittleEndianNbtTag::COMPOUND || !is_array($effectsTag->value)
                    || count($effectsTag->value) > ($schemaVersion >= 10 ? 291 : count(EffectType::cases()))) {
                    throw new CorruptPlayerDataException('Player effects list is malformed or exceeds its limit.');
                }
                $seenEffects = [];
                $hiddenEffects = [];
                $infiniteElapsedTicks = [];
                $totalHidden = 0;
                foreach ($effectsTag->value as $item) {
                    if (!$item instanceof LittleEndianNbtTag) {
                        throw new CorruptPlayerDataException('Player effects contain an invalid entry.');
                    }
                    $tags = self::compound($item, 'Effects');
                    self::assertExactTags(
                        $tags,
                        $schemaVersion >= 10
                            ? ['Type', 'Duration', 'Amplifier', 'Visible', 'Ambient', 'Infinite', 'Active', 'Order', 'Phase']
                            : ['Type', 'Duration', 'Amplifier', 'Visible', 'Ambient', 'Infinite'],
                        'effect entry',
                    );
                    $type = EffectType::tryFrom(self::string($tags['Type'], 'Effects.Type'));
                    if ($type === null) {
                        throw new CorruptPlayerDataException('Player effect type is unsupported.');
                    }
                    $effect = new EffectInstance(
                        $type,
                        self::integer($tags['Duration'], LittleEndianNbtTag::INT, 'Effects.Duration'),
                        self::integer($tags['Amplifier'], LittleEndianNbtTag::BYTE, 'Effects.Amplifier'),
                        self::integer($tags['Visible'], LittleEndianNbtTag::BYTE, 'Effects.Visible') === 1,
                        self::integer($tags['Ambient'], LittleEndianNbtTag::BYTE, 'Effects.Ambient') === 1,
                        self::integer($tags['Infinite'], LittleEndianNbtTag::BYTE, 'Effects.Infinite') === 1,
                    );
                    if (!$effect->infinite && $effect->durationTicks === 0) {
                        throw new CorruptPlayerDataException('Persisted player effects must still be active.');
                    }
                    $active = $schemaVersion < 10
                        || self::integer($tags['Active'], LittleEndianNbtTag::BYTE, 'Effects.Active') === 1;
                    if ($active) {
                        if (isset($seenEffects[$type->value])) {
                            throw new CorruptPlayerDataException('Player active effect type is duplicated.');
                        }
                        $effects[] = $effect;
                        $seenEffects[$type->value] = $effect;
                        if ($schemaVersion >= 10) {
                            $order = self::integer($tags['Order'], LittleEndianNbtTag::SHORT, 'Effects.Order');
                            $phase = self::integer($tags['Phase'], LittleEndianNbtTag::INT, 'Effects.Phase');
                            if ($order !== 0 || $phase < 0) {
                                throw new CorruptPlayerDataException('Player active effect persistence metadata is invalid.');
                            }
                            $infiniteElapsedTicks[$type->value] = $phase;
                        }
                        continue;
                    }
                    $order = self::integer($tags['Order'], LittleEndianNbtTag::SHORT, 'Effects.Order');
                    $phase = self::integer($tags['Phase'], LittleEndianNbtTag::INT, 'Effects.Phase');
                    if ($order < 0 || $order >= 32 || $phase !== 0
                        || isset($hiddenEffects[$type->value][$order])) {
                        throw new CorruptPlayerDataException('Player hidden effect persistence metadata is invalid.');
                    }
                    $hiddenEffects[$type->value][$order] = $effect;
                    if (++$totalHidden > 256) {
                        throw new CorruptPlayerDataException('Player hidden effect state exceeds its total limit.');
                    }
                }
                foreach ($hiddenEffects as $key => &$chain) {
                    if (!isset($seenEffects[$key])) {
                        throw new CorruptPlayerDataException('Player hidden effect has no active owner.');
                    }
                    ksort($chain);
                    if (array_keys($chain) !== range(0, count($chain) - 1)) {
                        throw new CorruptPlayerDataException('Player hidden effect order is not contiguous.');
                    }
                    $chain = array_values($chain);
                }
                unset($chain);
                if ($hiddenEffects !== [] || array_filter($infiniteElapsedTicks) !== []) {
                    $effectPersistenceState = new ActiveEffectPersistenceState(
                        $seenEffects,
                        $hiddenEffects,
                        $infiniteElapsedTicks,
                    );
                }
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
                    $armor,
                    $offhand,
                    $enderChest,
                ),
                self::integer($root['FirstPlayed'], LittleEndianNbtTag::LONG, 'FirstPlayed'),
                self::integer($root['LastPlayed'], LittleEndianNbtTag::LONG, 'LastPlayed'),
                self::string($root['GameMode'], 'GameMode'),
                isset($root['Health']) ? self::floating($root['Health'], 'Health') : 20.0,
                isset($root['FoodLevel'])
                    ? self::floating($root['FoodLevel'], 'FoodLevel')
                    : PlayerVitals::MAX_FOOD,
                isset($root['Saturation'])
                    ? self::floating($root['Saturation'], 'Saturation')
                    : PlayerVitals::MAX_SATURATION,
                isset($root['Exhaustion']) ? self::floating($root['Exhaustion'], 'Exhaustion') : 0.0,
                $effects,
                isset($root['Absorption']) ? self::floating($root['Absorption'], 'Absorption') : 0.0,
                isset($root['AirTicks'])
                    ? self::integer($root['AirTicks'], LittleEndianNbtTag::INT, 'AirTicks')
                    : PlayerVitals::MAX_AIR_TICKS,
                isset($root['FireTicks'])
                    ? self::integer($root['FireTicks'], LittleEndianNbtTag::INT, 'FireTicks')
                    : 0,
                $effectPersistenceState,
            );
        } catch (CorruptPlayerDataException $error) {
            throw $error;
        } catch (InvalidArgumentException $error) {
            throw new CorruptPlayerDataException('Player profile contains an invalid value.', previous: $error);
        }
    }

    private static function encodedStack(
        PlayerInventoryStackState $stack,
        ?int $slot = null,
    ): LittleEndianNbtTag {
        $tags = [
            'Identifier' => LittleEndianNbtTag::string($stack->identifier),
            'Count' => LittleEndianNbtTag::byte($stack->count),
            'Damage' => LittleEndianNbtTag::int($stack->damage),
            'ItemNbt' => new LittleEndianNbtTag(LittleEndianNbtTag::BYTE_ARRAY, $stack->nbt?->toBinary() ?? ''),
            'Aux' => LittleEndianNbtTag::int($stack->auxValue),
        ];
        if ($slot !== null) {
            $tags = ['Slot' => LittleEndianNbtTag::byte($slot), ...$tags];
        }

        return LittleEndianNbtTag::compound($tags);
    }

    private static function encodedEffect(
        EffectInstance $effect,
        bool $active,
        int $order,
        int $phase,
    ): LittleEndianNbtTag {
        return LittleEndianNbtTag::compound([
            'Type' => LittleEndianNbtTag::string($effect->type->value),
            'Duration' => LittleEndianNbtTag::int($effect->durationTicks),
            'Amplifier' => LittleEndianNbtTag::byte($effect->amplifier),
            'Visible' => LittleEndianNbtTag::byte($effect->visible ? 1 : 0),
            'Ambient' => LittleEndianNbtTag::byte($effect->ambient ? 1 : 0),
            'Infinite' => LittleEndianNbtTag::byte($effect->infinite ? 1 : 0),
            'Active' => LittleEndianNbtTag::byte($active ? 1 : 0),
            'Order' => new LittleEndianNbtTag(LittleEndianNbtTag::SHORT, $order),
            'Phase' => LittleEndianNbtTag::int($phase),
        ]);
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
        $auxValue = $schemaVersion >= 5
            ? self::integer($tags['Aux'], LittleEndianNbtTag::INT, "$path.Aux")
            : 0;
        self::validateStack($identifier, $count, $damage, $auxValue);

        return new PlayerInventoryStackState($identifier, $count, $damage, $itemNbt, $auxValue);
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
        if ($schemaVersion >= 5) {
            $tags[] = 'Aux';
        }

        return $tags;
    }

    private static function validateStack(string $identifier, int $count, int $damage, int $auxValue): void
    {
        if (strlen($identifier) > 256 || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
            || $count < 1 || $count > 64
            || $damage < 0 || $damage > PlayerInventoryStackState::MAX_DAMAGE
            || $auxValue < 0 || $auxValue > PlayerInventoryStackState::MAX_AUX_VALUE) {
            throw new CorruptPlayerDataException('Player inventory stack is invalid.');
        }
    }
}
