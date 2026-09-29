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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Api\Processing\CauldronContentType;
use InvalidArgumentException;

final readonly class CauldronState
{
    public function __construct(
        public CauldronContentType $content,
        public int $level,
        public ?int $potionAuxValue = null,
    ) {
        if ($level < 0 || $level > 6 || ($content === CauldronContentType::EMPTY) !== ($level === 0)) {
            throw new InvalidArgumentException('Cauldron content and level are inconsistent.');
        }
        if (($content === CauldronContentType::POTION) !== ($potionAuxValue !== null)
            || ($potionAuxValue !== null && ($potionAuxValue < 0 || $potionAuxValue > 32_767))) {
            throw new InvalidArgumentException('Cauldron potion metadata is inconsistent or outside its bounds.');
        }
        if (($content === CauldronContentType::LAVA || $content === CauldronContentType::POWDER_SNOW) && $level !== 6) {
            throw new InvalidArgumentException('Lava and powder-snow cauldrons must be full.');
        }
    }

    public static function empty(): self
    {
        return new self(CauldronContentType::EMPTY, 0);
    }
}
