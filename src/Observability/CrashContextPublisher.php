<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

interface CrashContextPublisher
{
    /** @param list<CrashPlayer> $players */
    public function publishRuntime(int $tick, array $players, ?CrashPlayer $involvedPlayer = null): void;

    public function publishPlugin(?string $attribution): void;
}
