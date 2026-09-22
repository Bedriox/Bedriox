<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
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
        $nbt ??= ItemNbt::empty();
        $nbt = $damage === 0
            ? $nbt->withoutTag('Damage')
            : $nbt->withTag('Damage', Tag::int($damage));

        return $nbt->isEmpty()
            ? self::EMPTY
            : self::NBT_PREFIX . $nbt->toBinary() . self::EMPTY_RESTRICTIONS;
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

    private function __construct() {}
}
