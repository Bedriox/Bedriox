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

namespace Bedriox\Server\ResourcePack;

use InvalidArgumentException;

final readonly class ResourcePackArchive
{
    public const int CHUNK_BYTES = 262_144;

    public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public int $size,
        public string $sha256,
        public string $contentKey,
        public string $subPackName,
        public string $snapshotPath,
        public string $cdnUrl = '',
        /** @var array<string, string> UUID to exact version */
        public array $dependencies = [],
    ) {
        if ($this->size < 1 || strlen($this->sha256) !== 32) {
            throw new InvalidArgumentException('Resource-pack archive metadata is invalid.');
        }
    }

    public function wireId(): string
    {
        return strtolower($this->id) . '_' . $this->version;
    }

    public function chunkCount(): int
    {
        return intdiv($this->size + self::CHUNK_BYTES - 1, self::CHUNK_BYTES);
    }

    public function readChunk(int $index): string
    {
        if ($index < 0 || $index >= $this->chunkCount()) {
            throw new ResourcePackException('Resource-pack chunk index is outside the archive.');
        }
        $offset = $index * self::CHUNK_BYTES;
        $length = min(self::CHUNK_BYTES, $this->size - $offset);
        if ($length < 1) {
            throw new ResourcePackException('Resource-pack chunk is empty.');
        }
        $handle = @fopen($this->snapshotPath, 'rb');
        if ($handle === false) {
            throw new ResourcePackException('Resource-pack snapshot is unavailable.');
        }
        try {
            if (fseek($handle, $offset, SEEK_SET) !== 0) {
                throw new ResourcePackException('Resource-pack snapshot could not be positioned.');
            }
            $bytes = fread($handle, $length);
            if (!is_string($bytes) || strlen($bytes) !== $length) {
                throw new ResourcePackException('Resource-pack snapshot could not be read completely.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }
}
