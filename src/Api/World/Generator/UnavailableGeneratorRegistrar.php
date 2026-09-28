<?php

declare(strict_types=1);

namespace Bedriox\Api\World\Generator;

use LogicException;

/** @internal */
final class UnavailableGeneratorRegistrar implements GeneratorRegistrar
{
    public function register(GeneratorDefinition $definition, bool $replace = false): void
    {
        throw new LogicException('World-generator registration is unavailable in this plugin context.');
    }
}
