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

use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;

final readonly class FurnaceRecipe
{
    public function __construct(
        public string $identifier,
        public string $inputIdentifier,
        public ?int $inputAuxValue,
        public ContainerItemStack $output,
        public float $experience,
    ) {
        if ($identifier === '' || strlen($identifier) > 256 || $experience < 0.0 || $experience > 100.0) {
            throw new InvalidArgumentException('Furnace recipe metadata is invalid.');
        }
    }

    public function matches(ContainerItemStack $input): bool
    {
        return $input->identifier === $this->inputIdentifier
            && ($this->inputAuxValue === null || $input->auxValue === $this->inputAuxValue);
    }
}
