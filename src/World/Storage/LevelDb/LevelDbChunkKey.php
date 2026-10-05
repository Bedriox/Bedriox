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

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Api\World\WorldDimension;
use InvalidArgumentException;

/** Binary Mojang LevelDB keys for a chunk in one native Bedrock dimension. */
final class LevelDbChunkKey
{
    public const string DATA_3D = "\x2b";
    public const string VERSION = "\x2c";
    public const string SUBCHUNK = "\x2f";
    public const string BLOCK_ENTITIES = "\x31";
    public const string FINALIZATION = "\x36";

    private function __construct() {}

    public static function prefix(
        int $chunkX,
        int $chunkZ,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): string {
        $prefix = self::signedLittleEndian32($chunkX) . self::signedLittleEndian32($chunkZ);

        return match ($dimension) {
            WorldDimension::OVERWORLD => $prefix,
            WorldDimension::NETHER => $prefix . self::signedLittleEndian32(1),
            WorldDimension::END => $prefix . self::signedLittleEndian32(2),
        };
    }

    public static function version(int $chunkX, int $chunkZ, WorldDimension $dimension = WorldDimension::OVERWORLD): string
    {
        return self::prefix($chunkX, $chunkZ, $dimension) . self::VERSION;
    }

    public static function data3d(int $chunkX, int $chunkZ, WorldDimension $dimension = WorldDimension::OVERWORLD): string
    {
        return self::prefix($chunkX, $chunkZ, $dimension) . self::DATA_3D;
    }

    public static function finalization(int $chunkX, int $chunkZ, WorldDimension $dimension = WorldDimension::OVERWORLD): string
    {
        return self::prefix($chunkX, $chunkZ, $dimension) . self::FINALIZATION;
    }

    public static function blockEntities(int $chunkX, int $chunkZ, WorldDimension $dimension = WorldDimension::OVERWORLD): string
    {
        return self::prefix($chunkX, $chunkZ, $dimension) . self::BLOCK_ENTITIES;
    }

    public static function subChunk(
        int $chunkX,
        int $chunkZ,
        int $sectionY,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): string {
        if ($sectionY < -128 || $sectionY > 127) {
            throw new InvalidArgumentException('LevelDB subchunk Y must fit a signed byte.');
        }
        return self::prefix($chunkX, $chunkZ, $dimension) . self::SUBCHUNK . chr($sectionY & 0xff);
    }

    private static function signedLittleEndian32(int $value): string
    {
        if ($value < -2_147_483_648 || $value > 2_147_483_647) {
            throw new InvalidArgumentException('LevelDB chunk coordinate must fit a signed 32-bit integer.');
        }
        return pack('V', $value & 0xffff_ffff);
    }
}
