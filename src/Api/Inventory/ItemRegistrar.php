<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

interface ItemRegistrar
{
    public function register(ItemDefinition $definition, bool $replace = false): void;
}
