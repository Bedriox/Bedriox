<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

use Bedriox\Api\Command\CommandSoftEnum;

/** @internal */
final class CommandSoftEnumRecord
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public readonly int $id,
        public readonly string $owner,
        public readonly bool $pluginOwned,
        public readonly string $name,
        public array $values,
        public readonly CommandSoftEnum $handle,
    ) {}
}
