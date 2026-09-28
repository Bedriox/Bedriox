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

use InvalidArgumentException;

final readonly class StoredSubChunk
{
    /** @var list<PersistentBlockStorage> */
    private array $storages;

    /** @param list<PersistentBlockStorage> $storages */
    public function __construct(public int $sectionY, array $storages, public int $sourceVersion = 8)
    {
        if ($sectionY < -128 || $sectionY > 127) {
            throw new InvalidArgumentException('Stored subchunk Y must fit a signed byte.');
        }
        if ($storages === [] || count($storages) > 16) {
            throw new InvalidArgumentException('Stored subchunk must contain between 1 and 16 layers.');
        }
        if ($sourceVersion !== 8 && $sourceVersion !== 9) {
            throw new InvalidArgumentException('Stored subchunk source version must be 8 or 9.');
        }
        $this->storages = $storages;
    }

    /** @return list<PersistentBlockStorage> */
    public function storages(): array
    {
        return $this->storages;
    }
}
