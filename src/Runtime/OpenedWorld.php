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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\World;
use LogicException;

/** One named world family rooted in the Overworld and backed by one provider owner. */
final readonly class OpenedWorld
{
    /** @var array<string, self> */
    private array $dimensions;

    /** @param iterable<self> $additionalDimensions */
    public function __construct(
        public World $world,
        public WorldData $data,
        iterable $additionalDimensions = [],
    ) {
        $root = $world->dimension();
        $dimensions = [$root->value => $this];
        foreach ($additionalDimensions as $opened) {
            if ($root !== WorldDimension::OVERWORLD) {
                throw new LogicException('Only an Overworld can root a named world family.');
            }
            if ($opened->dimensions !== [$opened->world->dimension()->value => $opened]) {
                throw new LogicException('Nested opened-world families are not supported.');
            }
            $dimension = $opened->world->dimension();
            if ($dimension === WorldDimension::OVERWORLD || isset($dimensions[$dimension->value])) {
                throw new LogicException('Opened world family contains a duplicate dimension.');
            }
            $dimensions[$dimension->value] = $opened;
        }
        $this->dimensions = $dimensions;
    }

    public function dimension(WorldDimension $dimension = WorldDimension::OVERWORLD): ?self
    {
        return $this->dimensions[$dimension->value] ?? null;
    }

    /** @return list<self> */
    public function dimensions(): array
    {
        return array_values($this->dimensions);
    }
}
