<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\TranslatableMessage;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\DeathInfoPacket;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\MovePlayerPacket;
use Bedriox\Protocol\Packet\RespawnPacket;
use Bedriox\Protocol\Packet\RespawnState;
use Bedriox\Protocol\Packet\SystemTextPacket;
use Bedriox\Protocol\Packet\TranslatedTextPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;

final class PlayerLifecyclePacketProjectionTest extends TestCase
{
    public function testDamageDeathAndHandshakeProjectTheCompletePacketConversation(): void
    {
        $encoder = new BedrockWorldEventPacketEncoder();
        $alive = self::snapshot(17.0, true);
        $damage = $encoder->encode(new PlayerDamaged($alive, 3.0, DamageCause::Fall, ['one', 'two']), []);
        self::assertCount(3, $damage);
        self::assertInstanceOf(UpdateAttributesPacket::class, $damage[0]->packet);
        self::assertSame(17.0, $damage[0]->packet->attributes[0]->value);
        self::assertInstanceOf(ActorEventPacket::class, $damage[1]->packet);
        self::assertInstanceOf(ActorEventPacket::class, $damage[2]->packet);
        self::assertSame(ActorEventType::Hurt, $damage[1]->packet->event);
        self::assertSame(ActorEventType::Hurt, $damage[2]->packet->event);

        $dead = self::snapshot(0.0, false);
        $message = new TranslatableMessage('death.fell.accident.generic', ['Player']);
        $death = $encoder->encode(new PlayerDied(
            $dead,
            DamageCause::Fall,
            null,
            $message,
            $message,
            ['one', 'two'],
            ['one', 'two'],
        ), []);
        self::assertCount(6, $death);
        self::assertInstanceOf(ActorEventPacket::class, $death[0]->packet);
        self::assertSame(ActorEventType::Death, $death[0]->packet->event);
        self::assertInstanceOf(RespawnPacket::class, $death[2]->packet);
        self::assertSame(RespawnState::ServerSearching, $death[2]->packet->state);
        self::assertInstanceOf(DeathInfoPacket::class, $death[3]->packet);
        self::assertSame('death.fell.accident.generic', $death[3]->packet->message);
        self::assertSame(['Player'], $death[3]->packet->parameters);
        self::assertInstanceOf(TranslatedTextPacket::class, $death[4]->packet);
        self::assertSame('death.fell.accident.generic', $death[4]->packet->message);
        self::assertEquals($death[4]->packet, $death[5]->packet);

        $ready = $encoder->encode(new RespawnAcknowledged($dead), []);
        self::assertCount(1, $ready);
        self::assertInstanceOf(RespawnPacket::class, $ready[0]->packet);
        self::assertSame(RespawnState::ServerReady, $ready[0]->packet->state);
    }

    public function testRespawnResynchronizesHealthPositionAnimationAndInventory(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
        $encoder = new BedrockWorldEventPacketEncoder(inventory: $projector);
        $inventory = array_fill(0, 36, null);
        $inventory[0] = new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock);

        $packets = $encoder->encode(new PlayerRespawned(
            self::snapshot(20.0, true),
            ['one', 'two'],
            $inventory,
            0,
            $inventory[0],
        ), []);

        self::assertCount(7, $packets);
        self::assertInstanceOf(UpdateAttributesPacket::class, $packets[0]->packet);
        self::assertInstanceOf(MovePlayerPacket::class, $packets[1]->packet);
        self::assertInstanceOf(ActorEventPacket::class, $packets[2]->packet);
        self::assertSame(ActorEventType::Respawn, $packets[2]->packet->event);
        self::assertInstanceOf(ActorEventPacket::class, $packets[3]->packet);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[4]->packet);
        self::assertInstanceOf(InventoryContentPacket::class, $packets[5]->packet);
        self::assertCount(36, $packets[5]->packet->items);
        self::assertInstanceOf(MobEquipmentPacket::class, $packets[6]->packet);
    }

    public function testDeathChatAndScreenCanBeCustomizedOrSuppressedIndependently(): void
    {
        $encoder = new BedrockWorldEventPacketEncoder();
        $dead = self::snapshot(0.0, false);

        $rawChat = $encoder->encode(new PlayerDied(
            $dead,
            DamageCause::Plugin,
            null,
            'A custom death message',
            null,
            ['one'],
            ['one', 'two'],
        ), []);

        self::assertCount(5, $rawChat);
        self::assertInstanceOf(ActorEventPacket::class, $rawChat[0]->packet);
        self::assertInstanceOf(RespawnPacket::class, $rawChat[1]->packet);
        self::assertInstanceOf(DeathInfoPacket::class, $rawChat[2]->packet);
        self::assertSame('', $rawChat[2]->packet->message);
        self::assertInstanceOf(SystemTextPacket::class, $rawChat[3]->packet);
        self::assertSame('A custom death message', $rawChat[3]->packet->message);
        self::assertEquals($rawChat[3]->packet, $rawChat[4]->packet);
        self::assertSame(['one', 'one', 'one', 'one', 'two'], array_map(static fn($packet): string => $packet->sessionId, $rawChat));

        $screenOnly = $encoder->encode(new PlayerDied(
            $dead,
            DamageCause::Plugin,
            null,
            null,
            'Custom screen',
            ['one'],
            ['one', 'two'],
        ), []);
        self::assertCount(3, $screenOnly);
        self::assertInstanceOf(DeathInfoPacket::class, $screenOnly[2]->packet);
        self::assertSame('Custom screen', $screenOnly[2]->packet->message);
        self::assertSame([], $screenOnly[2]->packet->parameters);
    }

    private static function snapshot(float $health, bool $alive): PlayerSnapshot
    {
        return new PlayerSnapshot(
            'one',
            '00000000-0000-0000-0000-000000000001',
            'Player',
            new Position(1.0, 64.0, 2.0),
            0.0,
            0.0,
            MovementMode::STOPPED,
            7,
            VerticalState::GROUNDED,
            0.0,
            41,
            health: $health,
            alive: $alive,
        );
    }
}
