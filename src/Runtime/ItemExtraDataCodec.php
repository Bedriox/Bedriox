<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Protocol\Packet\InventoryItemStack;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use InvalidArgumentException;

/** Encodes the Bedrock item extra-data envelope without admitting client-owned inventory state. */
final class ItemExtraDataCodec
{
    private const string EMPTY = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    private const string NBT_PREFIX = "\xff\xff\x01";
    private const string EMPTY_RESTRICTIONS = "\x00\x00\x00\x00\x00\x00\x00\x00";

    public static function encode(?ItemNbt $nbt, int $damage = 0): string
    {
        if ($damage < 0 || $damage > 65_535) {
            throw new InvalidArgumentException('Item damage is outside its supported range.');
        }
        $codec = new LittleEndianNbtCodec();
        $root = $codec->decodeRootCompound(($nbt ?? ItemNbt::empty())->toBinary());
        unset($root['Damage']);
        if ($damage !== 0) {
            $root['Damage'] = Tag::int($damage)->toInternal();
        }

        return self::encodeBinary($root === [] ? null : $codec->encodeRootCompound($root));
    }

    /** Encodes admitted creative NBT verbatim; variant data belongs in the wire auxiliary field. */
    public static function encodeCreative(?ItemNbt $nbt): string
    {
        return self::encodeBinary($nbt === null || $nbt->isEmpty() ? null : $nbt->toBinary());
    }

    public static function decode(string $bytes): ?ItemNbt
    {
        if ($bytes === '' || $bytes === self::EMPTY) {
            return null;
        }
        if (strlen($bytes) < 15 || !str_starts_with($bytes, self::NBT_PREFIX)
            || !str_ends_with($bytes, self::EMPTY_RESTRICTIONS)) {
            throw new InvalidArgumentException('Client item extra data is unsupported.');
        }
        $nbt = ItemNbt::fromBinary(substr($bytes, 3, -8));

        return $nbt->isEmpty() ? null : $nbt;
    }

    private static function encodeBinary(?string $nbt): string
    {
        $encoded = $nbt === null ? self::EMPTY : self::NBT_PREFIX . $nbt . self::EMPTY_RESTRICTIONS;
        if (strlen($encoded) > InventoryItemStack::MAXIMUM_USER_DATA_BYTES) {
            throw new InvalidArgumentException('Item extra data exceeds its wire byte limit.');
        }

        return $encoded;
    }

    private function __construct() {}
}
