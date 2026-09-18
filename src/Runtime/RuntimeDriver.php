<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

interface RuntimeDriver
{
    public function poll(): bool;

    public function close(): void;
}
