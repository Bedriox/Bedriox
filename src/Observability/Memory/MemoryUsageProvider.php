<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability\Memory;

interface MemoryUsageProvider
{
    public function snapshot(): MemorySnapshot;
}
