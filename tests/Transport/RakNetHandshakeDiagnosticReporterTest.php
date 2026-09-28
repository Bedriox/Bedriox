<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Tests\Transport;

use Bedriox\RakNet\ConnectedHandshakeDiagnosticBatch;
use Bedriox\RakNet\ConnectedHandshakeDiagnosticEvent;
use Bedriox\RakNet\ConnectedHandshakeRejectionReason;
use Bedriox\RakNet\ConnectedHandshakeStage;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Transport\RakNetHandshakeDiagnosticReporter;
use PHPUnit\Framework\TestCase;

final class RakNetHandshakeDiagnosticReporterTest extends TestCase
{
    public function testReportsOnlySafeStructuredHandshakeMetadata(): void
    {
        $lines = [];
        $reporter = new RakNetHandshakeDiagnosticReporter(new RuntimeDiagnostics(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));
        $reporter->report(new ConnectedHandshakeDiagnosticBatch([
            new ConnectedHandshakeDiagnosticEvent(
                '192.168.1.13',
                50_000,
                ConnectedHandshakeStage::AwaitingNewIncomingConnection,
                ConnectedHandshakeRejectionReason::InvalidEnvelope,
                0x80,
                0x13,
                164,
                Reliability::ReliableOrdered,
                0,
            ),
        ], 2));

        self::assertCount(2, $lines);
        $rejection = self::decode($lines[0]);
        self::assertSame('transport.protocol_trace', $rejection['event']);
        self::assertSame('handshake_rejected', $rejection['kind']);
        self::assertSame('192.168.1.13', $rejection['remote_address']);
        self::assertSame(50_000, $rejection['remote_port']);
        self::assertSame('awaiting_new_incoming_connection', $rejection['phase']);
        self::assertSame('invalid_envelope', $rejection['reason']);
        self::assertSame(0x80, $rejection['datagram_id']);
        self::assertSame(0x13, $rejection['packet_id']);
        self::assertSame(164, $rejection['payload_length']);
        self::assertSame(Reliability::ReliableOrdered->value, $rejection['reliability']);
        self::assertSame(0, $rejection['ordering_channel']);
        self::assertArrayNotHasKey('payload', $rejection);
        self::assertArrayNotHasKey('guid', $rejection);

        $dropped = self::decode($lines[1]);
        self::assertSame('handshake_diagnostics_dropped', $dropped['kind']);
        self::assertSame(2, $dropped['count']);
    }

    /** @return array<mixed> */
    private static function decode(string $line): array
    {
        $decoded = json_decode(substr($line, strlen('[bedriox] ')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
