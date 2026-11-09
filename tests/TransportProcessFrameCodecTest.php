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

namespace Bedriox\Server\Tests;

use Bedriox\NetherNet\Protocol\Reliability;
use Bedriox\Server\Transport\Process\TransportProcessFrame;
use Bedriox\Server\Transport\Process\TransportProcessFrameCodec;
use Bedriox\Server\Transport\Process\TransportProcessFrameKind;
use PHPUnit\Framework\TestCase;

final class TransportProcessFrameCodecTest extends TestCase
{
    public function testSecuritySnapshotMetadataRoundTrips(): void
    {
        $codec = new TransportProcessFrameCodec();
        $frame = new TransportProcessFrame(TransportProcessFrameKind::SECURITY_SNAPSHOT, metadata: [
            'active_sessions' => 7,
            'maximum_sessions' => 100,
            'active_negotiations' => 3,
            'active_address_blocks' => 2,
            'negotiation_flood_blocks' => 4,
            'session_limit_rejections' => 5,
            'blocked_peer_rejections' => 6,
        ]);
        $encoded = $codec->encode($frame);
        $body = substr($encoded, TransportProcessFrameCodec::PREFIX_BYTES);

        self::assertEquals($frame, $codec->decode($body));
    }

    public function testAdvertisementMetadataRoundTrips(): void
    {
        $codec = new TransportProcessFrameCodec();
        $frame = new TransportProcessFrame(TransportProcessFrameKind::UPDATE_ADVERTISEMENT, metadata: [
            'server_name' => 'Bedriox Gateway',
            'online_players' => 37,
            'maximum_players' => 150,
        ]);
        $encoded = $codec->encode($frame);

        self::assertEquals(
            $frame,
            $codec->decode(substr($encoded, TransportProcessFrameCodec::PREFIX_BYTES)),
        );
    }

    public function testPayloadReceiptRoundTripsAcrossBothProcessDirections(): void
    {
        $codec = new TransportProcessFrameCodec();
        $send = new TransportProcessFrame(
            TransportProcessFrameKind::SEND_PAYLOAD,
            42,
            'resource-pack-chunk',
            Reliability::RELIABLE,
            receiptId: 73,
        );
        $acknowledgement = new TransportProcessFrame(
            TransportProcessFrameKind::PAYLOAD_ACKNOWLEDGED,
            42,
            metadata: ['receipt_id' => 73],
        );

        foreach ([$send, $acknowledgement] as $frame) {
            $encoded = $codec->encode($frame);
            self::assertEquals(
                $frame,
                $codec->decode(substr($encoded, TransportProcessFrameCodec::PREFIX_BYTES)),
            );
        }
    }
}
