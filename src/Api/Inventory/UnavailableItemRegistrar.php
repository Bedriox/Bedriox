<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use LogicException;

/** @internal */
final class UnavailableItemRegistrar implements ItemRegistrar
{
    public function register(ItemDefinition $definition, bool $replace = false): void
    {
        throw new LogicException('Item registration is unavailable in this plugin context.');
    }

    public function registerBehavior(
        string $identifier,
        ItemBehaviorDefinition $definition,
        bool $replace = false,
    ): void {
        throw new LogicException('Item behavior registration is unavailable in this plugin context.');
    }
}
