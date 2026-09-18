<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

final readonly class ChatBroadcast implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $senderSessionId,
        public string $senderIdentity,
        public string $senderDisplayName,
        public int $senderSequence,
        public string $message,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
