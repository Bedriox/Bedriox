<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport;

use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\Server\Runtime\RuntimeDiagnostics;

/** Production adapter for the RakNet discovery and connected transport. */
final readonly class DiscoveryServerTransport implements ConnectedTransport
{
    private RakNetHandshakeDiagnosticReporter $diagnosticReporter;

    public function __construct(private DiscoveryServer $server, ?RuntimeDiagnostics $diagnostics = null)
    {
        $this->diagnosticReporter = new RakNetHandshakeDiagnosticReporter(
            $diagnostics ?? RuntimeDiagnostics::disabled(),
        );
    }

    public function poll(int $maximumDatagrams): int
    {
        $handled = $this->server->poll($maximumDatagrams);
        $this->diagnosticReporter->report($this->server->drainHandshakeDiagnostics());

        return $handled;
    }

    public function drainSessionEvents(): array
    {
        return $this->server->drainSessionEvents();
    }

    public function drainReceivedPayloads(): array
    {
        return $this->server->drainReceivedPayloads();
    }

    public function sendPayload(
        string $remoteAddress,
        int $remotePort,
        string $payload,
        Reliability $reliability,
        int $orderingChannel = 0,
    ): void {
        $this->server->sendPayload($remoteAddress, $remotePort, $payload, $reliability, $orderingChannel);
    }

    public function removeSession(string $remoteAddress, int $remotePort): bool
    {
        return $this->server->removeSession($remoteAddress, $remotePort);
    }

    public function close(): void
    {
        $this->server->close();
    }
}
