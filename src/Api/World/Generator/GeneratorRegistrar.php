<?php

declare(strict_types=1);

namespace Bedriox\Api\World\Generator;

interface GeneratorRegistrar
{
    public function register(GeneratorDefinition $definition, bool $replace = false): void;
}
