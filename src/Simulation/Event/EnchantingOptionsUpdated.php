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

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Processing\EnchantingOption;
use InvalidArgumentException;

final readonly class EnchantingOptionsUpdated implements WorldEvent
{
    /** @var array<int, EnchantingOption> */
    public array $options;

    /** @param array<mixed> $options */
    public function __construct(public string $sessionId, array $options)
    {
        if (count($options) > 3) {
            throw new InvalidArgumentException('Enchanting options must be bounded.');
        }
        foreach ($options as $networkId => $option) {
            if (!is_int($networkId) || $networkId < 1 || $networkId > 0xffffffff
                || !$option instanceof EnchantingOption) {
                throw new InvalidArgumentException('Enchanting options contain an invalid entry.');
            }
        }
        $this->options = $options;
    }

    public function recipients(): array
    {
        return [$this->sessionId];
    }
}
