<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage;

interface AtomicFileWriter
{
    public function write(string $path, string $contents): void;
}
