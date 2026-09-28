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

namespace Bedriox\Server\World\Storage;

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LevelDatCodec;
use Bedriox\Server\World\Storage\Nbt\LevelDatMetadata;

final readonly class LevelDatStore
{
    public function __construct(
        private LevelDatCodec $codec = new LevelDatCodec(),
        private AtomicFileWriter $writer = new LocalAtomicFileWriter(),
    ) {}

    public function load(string $path): LevelDatMetadata
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new CorruptWorldDataException('Unable to read level.dat.');
        }
        try {
            $maximumFileBytes = LevelDatCodec::HEADER_BYTES + LevelDatCodec::MAX_NBT_BYTES;
            $contents = @stream_get_contents($handle, $maximumFileBytes + 1);
            if ($contents === false) {
                throw new CorruptWorldDataException('Unable to read level.dat.');
            }
            if (strlen($contents) > $maximumFileBytes || !feof($handle)) {
                throw new CorruptWorldDataException('level.dat exceeds the configured size limit.');
            }
        } finally {
            fclose($handle);
        }

        return $this->codec->decode($contents);
    }

    public function save(string $path, LevelDatMetadata $metadata): void
    {
        $this->writer->write($path, $this->codec->encode($metadata));
    }
}
