<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

interface JwkProvider
{
    /** @return list<array<string, mixed>> */
    public function keys(): array;
}
