<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Command;

final class NullConsoleInput implements ConsoleInput
{
    public function readAvailable(): array
    {
        return [];
    }

    public function close(): void {}
}
