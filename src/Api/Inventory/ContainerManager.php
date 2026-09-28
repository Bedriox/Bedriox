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

namespace Bedriox\Api\Inventory;

use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\World;
use Closure;
use InvalidArgumentException;

/** Public entry point for looking up world storage and creating plugin-owned inventories. */
final readonly class ContainerManager
{
    private Closure $guard;

    /**
     * @param Closure(World, BlockPosition): ?Container $at
     * @param Closure(ContainerLayout, ?string): Container $create
     * @internal The server owns manager construction.
     */
    public function __construct(
        private Closure $at,
        private Closure $create,
        ?Closure $guard = null,
    ) {
        $this->guard = $guard ?? static function (): void {};
    }

    public function at(World $world, BlockPosition $position): ?Container
    {
        ($this->guard)();

        return ($this->at)($world, $position);
    }

    public function create(
        ContainerLayout $layout = ContainerLayout::SINGLE_CHEST,
        ?string $title = null,
    ): Container {
        ($this->guard)();
        if ($title !== null && ($title === '' || strlen($title) > 256 || preg_match('//u', $title) !== 1)) {
            throw new InvalidArgumentException('Virtual inventory title must be bounded non-empty UTF-8.');
        }

        return ($this->create)($layout, $title);
    }
}
