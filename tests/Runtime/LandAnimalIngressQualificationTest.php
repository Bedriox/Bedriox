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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\ContainerClosePacket;
use Bedriox\Protocol\Packet\ContainerOpenPacket;
use Bedriox\Protocol\Packet\ContainerType;
use Bedriox\Protocol\Packet\HorseEquipmentNbt;
use Bedriox\Protocol\Packet\HorseEquipmentSlot;
use Bedriox\Protocol\Packet\InventoryItemStack;
use Bedriox\Protocol\Packet\InventoryTransactionPacket;
use Bedriox\Protocol\Packet\InventoryVector3;
use Bedriox\Protocol\Packet\ItemUseOnEntityActionType;
use Bedriox\Protocol\Packet\ItemUseOnEntityInventoryTransaction;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\SetLocalPlayerAsInitializedPacket;
use Bedriox\Protocol\Packet\UpdateEquipPacket;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Runtime\BedrockPlayChannel;
use Bedriox\Server\Simulation\Command\CloseContainer;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use PHPUnit\Framework\TestCase;

final class LandAnimalIngressQualificationTest extends TestCase
{
    public function testMalformedEntityIdentityAndHotbarAreBoundedNoOps(): void
    {
        [$channel, $client, , $self] = $this->channel();
        self::assertTrue($this->accept($channel, $client, [new SetLocalPlayerAsInitializedPacket($self)]));

        foreach ([
            [new UnsignedLong(1, 22), 0],
            [UnsignedLong::fromInt(22), -1],
            [UnsignedLong::fromInt(22), 9],
        ] as [$target, $hotbar]) {
            self::assertTrue($this->accept($channel, $client, [new InventoryTransactionPacket(
                0,
                [],
                [],
                new ItemUseOnEntityInventoryTransaction(
                    $target,
                    ItemUseOnEntityActionType::ItemInteract,
                    $hotbar,
                    InventoryItemStack::empty(),
                    new InventoryVector3(0.0, 64.0, 0.0),
                    new InventoryVector3(0.0, 64.0, 0.0),
                ),
            )]));
        }

        self::assertSame([], $channel->drainCommands());
        self::assertFalse($channel->isClosed());
    }

    public function testHorseCloseAliasAcknowledgesTheActualAuthoritativeWindow(): void
    {
        [$channel, $client, $server, $self] = $this->channel();
        self::assertTrue($this->accept($channel, $client, [new SetLocalPlayerAsInitializedPacket($self)]));
        foreach ($channel->drainOutgoing() as $payload) {
            $server->decryptEnvelope($payload->payload);
        }
        self::assertTrue($channel->queuePacket(new UpdateEquipPacket(
            7,
            ContainerType::Horse,
            1,
            512,
            HorseEquipmentNbt::encode([new HorseEquipmentSlot(0, ['minecraft:saddle'])]),
        )));
        $opened = $channel->drainOutgoing();
        self::assertCount(1, $opened);
        $server->decryptEnvelope($opened[0]->payload);
        self::assertTrue($channel->queuePacket(new ContainerClosePacket(
            7,
            ContainerType::Horse,
            true,
        )));
        $closed = $channel->drainOutgoing();
        self::assertCount(1, $closed);
        $server->decryptEnvelope($closed[0]->payload);

        self::assertTrue($this->accept($channel, $client, [new ContainerClosePacket(
            0xff,
            ContainerType::None,
            false,
        )]));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(CloseContainer::class, $commands[0]);
        self::assertSame(7, $commands[0]->windowId);

        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        $ack = $this->decode($server->decryptEnvelope($outgoing[0]->payload));
        self::assertInstanceOf(ContainerClosePacket::class, $ack);
        self::assertSame(7, $ack->containerId);
        self::assertSame(ContainerType::Horse, $ack->containerType);
        self::assertFalse($ack->serverInitiated);
        self::assertFalse($channel->isClosed());
    }

    public function testEntityBackedContainerCloseAliasAcknowledgesTheActualAuthoritativeWindow(): void
    {
        [$channel, $client, $server, $self] = $this->channel();
        self::assertTrue($this->accept($channel, $client, [new SetLocalPlayerAsInitializedPacket($self)]));
        foreach ($channel->drainOutgoing() as $payload) {
            $server->decryptEnvelope($payload->payload);
        }
        self::assertTrue($channel->queuePacket(new ContainerOpenPacket(
            8,
            ContainerType::ChestBoat,
            new \Bedriox\Protocol\Packet\BlockPosition(0, 0, 0),
            513,
        )));
        $opened = $channel->drainOutgoing();
        self::assertCount(1, $opened);
        $server->decryptEnvelope($opened[0]->payload);
        self::assertTrue($channel->queuePacket(new ContainerClosePacket(
            8,
            ContainerType::ChestBoat,
            true,
        )));
        $closed = $channel->drainOutgoing();
        self::assertCount(1, $closed);
        $server->decryptEnvelope($closed[0]->payload);

        self::assertTrue($this->accept($channel, $client, [new ContainerClosePacket(
            0xff,
            ContainerType::None,
            false,
        )]));
        $commands = $channel->drainCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf(CloseContainer::class, $commands[0]);
        self::assertSame(8, $commands[0]->windowId);
        $outgoing = $channel->drainOutgoing();
        self::assertCount(1, $outgoing);
        $ack = $this->decode($server->decryptEnvelope($outgoing[0]->payload));
        self::assertInstanceOf(ContainerClosePacket::class, $ack);
        self::assertSame(8, $ack->containerId);
        self::assertSame(ContainerType::ChestBoat, $ack->containerType);
        self::assertFalse($ack->serverInitiated);
        self::assertFalse($channel->isClosed());
    }

    /** @return array{BedrockPlayChannel, BedrockEncryptor, BedrockDecryptor, UnsignedLong} */
    private function channel(): array
    {
        $key = str_repeat("\x42", 32);
        $keys = (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate();
        $ready = new LoginChannelReady(
            new AuthenticatedLogin(
                'RealName',
                '00000000-0000-0000-0000-000000000001',
                'real-xuid',
                $keys->publicKey,
                new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []),
            ),
            new BedrockEncryptor($key),
            new BedrockDecryptor($key),
            2193,
        );
        $runtimeId = UnsignedLong::fromInt(7);

        return [
            new BedrockPlayChannel(
                $ready,
                'session',
                $runtimeId,
                [],
                fixedFlatRuntimeIds: ['air' => 1, 'bedrock' => 2, 'dirt' => 3, 'grass_block' => 4],
            ),
            new BedrockEncryptor($key),
            new BedrockDecryptor($key),
            $runtimeId,
        ];
    }

    /** @param list<Packet> $packets */
    private function accept(BedrockPlayChannel $channel, BedrockEncryptor $client, array $packets): bool
    {
        $frames = array_map(
            static fn(Packet $packet): PacketFrame => new PacketFrame(
                new PacketHeader(BedrockPacketCodec::packetId($packet)),
                BedrockPacketCodec::encode($packet),
            ),
            $packets,
        );
        $payload = BedrockBatchCodec::encode(new BedrockBatch(
            $frames,
            CompressionMode::NegotiatedZlib,
            NetworkCompressionPolicy::THRESHOLD_BYTES,
        ), new BatchLimits());

        return $channel->accept(new ConnectedPayloadEvent(
            $client->encryptEnvelope($payload),
            Reliability::ReliableOrdered,
            0,
        ));
    }

    private function decode(string $payload): Packet
    {
        $batch = BedrockBatchCodec::decode(
            $payload,
            CompressionMode::NegotiatedZlib,
            new BatchLimits(),
            NetworkCompressionPolicy::THRESHOLD_BYTES,
        );
        self::assertCount(1, $batch->packets);
        $frame = $batch->packets[0];

        return BedrockPacketCodec::decode($frame->header->packetId, $frame->payload);
    }
}
