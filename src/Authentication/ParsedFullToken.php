<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

/** @internal */
final readonly class ParsedFullToken
{
    /** @param array<string, mixed> $claims */
    public function __construct(
        public string $kid,
        public string $signingInput,
        public string $signature,
        public array $claims,
    ) {}
}
