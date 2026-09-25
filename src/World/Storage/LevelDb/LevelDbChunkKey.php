<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

use InvalidArgumentException;

/** Binary Mojang LevelDB keys for an overworld chunk. */
final class LevelDbChunkKey
{
    public const string DATA_3D = "\x2b";
    public const string VERSION = "\x2c";
    public const string SUBCHUNK = "\x2f";
    public const string BLOCK_ENTITIES = "\x31";
    public const string FINALIZATION = "\x36";

    private function __construct() {}

    public static function prefix(int $chunkX, int $chunkZ): string
    {
        return self::signedLittleEndian32($chunkX) . self::signedLittleEndian32($chunkZ);
    }

    public static function version(int $chunkX, int $chunkZ): string
    {
        return self::prefix($chunkX, $chunkZ) . self::VERSION;
    }

    public static function data3d(int $chunkX, int $chunkZ): string
    {
        return self::prefix($chunkX, $chunkZ) . self::DATA_3D;
    }

    public static function finalization(int $chunkX, int $chunkZ): string
    {
        return self::prefix($chunkX, $chunkZ) . self::FINALIZATION;
    }

    public static function blockEntities(int $chunkX, int $chunkZ): string
    {
        return self::prefix($chunkX, $chunkZ) . self::BLOCK_ENTITIES;
    }

    public static function subChunk(int $chunkX, int $chunkZ, int $sectionY): string
    {
        if ($sectionY < -128 || $sectionY > 127) {
            throw new InvalidArgumentException('LevelDB subchunk Y must fit a signed byte.');
        }
        return self::prefix($chunkX, $chunkZ) . self::SUBCHUNK . chr($sectionY & 0xff);
    }

    private static function signedLittleEndian32(int $value): string
    {
        if ($value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new InvalidArgumentException('LevelDB chunk coordinate must fit a signed 32-bit integer.');
        }
        return pack('V', $value & 0xffff_ffff);
    }
}
