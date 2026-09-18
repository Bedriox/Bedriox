<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport;

use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use Bedriox\RakNet\SessionClosedEvent;
use Bedriox\RakNet\SessionOpenedEvent;

/** Testable boundary around the concrete, non-blocking RakNet server. */
interface ConnectedTransport
{
    public function poll(int $maximumDatagrams): int;

    /** @return list<SessionOpenedEvent|SessionClosedEvent> */
    public function drainSessionEvents(): array;

    /** @return list<ReceivedPayload> */
    public function drainReceivedPayloads(): array;

    public function sendPayload(
        string $remoteAddress,
        int $remotePort,
        string $payload,
        Reliability $reliability,
        int $orderingChannel = 0,
    ): void;

    public function removeSession(string $remoteAddress, int $remotePort): bool;

    public function close(): void;
}
