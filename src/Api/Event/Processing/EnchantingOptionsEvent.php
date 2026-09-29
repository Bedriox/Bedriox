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

namespace Bedriox\Api\Event\Processing;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;

final class EnchantingOptionsEvent extends CancellableEvent
{
    /** @var list<EnchantingOption> */
    private array $options;

    /** @param array<mixed> $options */
    public function __construct(public readonly Player $player, public readonly BlockPosition $position, public readonly ItemStack $item, array $options)
    {
        $this->options = self::validate($options);
    }

    /** @return list<EnchantingOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @param array<mixed> $options */
    public function setOptions(array $options): void
    {
        $this->assertMutable();
        $this->options = self::validate($options);
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->options];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_array($state[1])) {
            throw new InvalidArgumentException('Invalid enchanting-options event state.');
        }
        parent::replaceState($state[0]);
        $this->options = self::validate($state[1]);
    }

    /**
     * @param array<mixed> $options
     * @return list<EnchantingOption>
     */
    private static function validate(array $options): array
    {
        if (!array_is_list($options) || count($options) > 3) {
            throw new InvalidArgumentException('Enchanting options must be a list of at most three entries.');
        }
        $validated = [];
        foreach ($options as $option) {
            if (!$option instanceof EnchantingOption) {
                throw new InvalidArgumentException('Enchanting options contain an invalid entry.');
            }
            $validated[] = $option;
        }
        return $validated;
    }
}
