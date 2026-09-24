<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

/** @internal */
final readonly class CommandSoftEnumUpdate
{
    /** @param list<string> $values */
    public function __construct(
        public string $name,
        public array $values,
    ) {}
}
