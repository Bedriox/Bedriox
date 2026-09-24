<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

interface ItemRegistrar
{
    public function register(ItemDefinition $definition, bool $replace = false): void;

    /** Registers gameplay behavior for an item already admitted by the active Bedrock data set. */
    public function registerBehavior(
        string $identifier,
        ItemBehaviorDefinition $definition,
        bool $replace = false,
    ): void;
}
