<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

final readonly class EmotePerformed implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $senderSessionId,
        public string $emoteId,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
