<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport;

use Bedriox\RakNet\ConnectedHandshakeDiagnosticBatch;
use Bedriox\Server\Runtime\RuntimeDiagnostics;

/** Maps bounded transport diagnostics into the server's safe structured trace. */
final readonly class RakNetHandshakeDiagnosticReporter
{
    public function __construct(private RuntimeDiagnostics $diagnostics) {}

    public function report(ConnectedHandshakeDiagnosticBatch $batch): void
    {
        foreach ($batch->events as $event) {
            $this->diagnostics->record('transport.protocol_trace', [
                'kind' => 'handshake_rejected',
                'remote_address' => $event->remoteAddress,
                'remote_port' => $event->remotePort,
                'phase' => strtolower($event->stage->value),
                'reason' => strtolower($event->reason->value),
                'datagram_id' => $event->datagramId,
                'packet_id' => $event->controlPacketId,
                'payload_length' => $event->payloadLength,
                'reliability' => $event->reliability?->value,
                'ordering_channel' => $event->orderingChannel,
            ]);
        }
        if ($batch->droppedEventCount > 0) {
            $this->diagnostics->record('transport.protocol_trace', [
                'kind' => 'handshake_diagnostics_dropped',
                'count' => $batch->droppedEventCount,
            ]);
        }
    }
}
