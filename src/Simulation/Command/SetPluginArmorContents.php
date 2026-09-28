<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventoryStack;

final readonly class SetPluginArmorContents implements WorldCommand
{
    /** @param list<InventoryStack|null> $contents */
    public function __construct(
        public string $session,
        public array $contents,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        $bytes = 48 + strlen($this->session);
        foreach ($this->contents as $stack) {
            $bytes += $stack === null ? 1 : 32 + strlen($stack->identifier) + strlen($stack->nbt?->toBinary() ?? '');
        }

        return $bytes;
    }
}
