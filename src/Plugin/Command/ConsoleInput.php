<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

interface ConsoleInput
{
    /** @return list<string> */
    public function readAvailable(): array;

    public function close(): void;
}
