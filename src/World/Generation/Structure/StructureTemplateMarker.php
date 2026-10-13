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

namespace Bedriox\Server\World\Generation\Structure;

use InvalidArgumentException;

final readonly class StructureTemplateMarker
{
    /** @var array<string, bool|int|string> */
    public array $attributes;

    /** @param array<string, bool|int|string> $attributes */
    public function __construct(
        public string $key,
        public StructureMarkerType $type,
        public string $identifier,
        public StructureCoordinate $coordinate,
        public StructureDirection $direction = StructureDirection::North,
        array $attributes = [],
    ) {
        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $key) !== 1
            || preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.\/-]{0,95}$/D', $identifier) !== 1
            || count($attributes) > 16) {
            throw new InvalidArgumentException('Structure template marker contains invalid bounded identity data.');
        }
        ksort($attributes, SORT_STRING);
        foreach ($attributes as $attribute => $value) {
            if (preg_match('/^[a-z0-9_.-]{1,48}$/D', $attribute) !== 1
                || (is_string($value) && (strlen($value) > 128 || !mb_check_encoding($value, 'UTF-8')))) {
                throw new InvalidArgumentException('Structure template marker contains invalid bounded attributes.');
            }
        }
        $this->attributes = $attributes;
    }
}
