<?php

declare(strict_types=1);

namespace Bedriox\Server\Environment;

final readonly class RuntimeIdentity
{
    /** @param list<string> $extensions */
    public function __construct(
        public string $phpVersion,
        public bool $threadSafe,
        public array $extensions,
    ) {}
}
