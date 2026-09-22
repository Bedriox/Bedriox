<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\Player\TitleTimes;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\SetTitlePacket;
use Bedriox\Protocol\Packet\SetTitleType;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Protocol\Packet\TextPacketType;
use Bedriox\Protocol\Packet\ToastRequestPacket;
use Bedriox\Protocol\Packet\TranslatedTextPacket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerConnectionTest extends TestCase
{
    public function testPlayerConvenienceMethodsUseTheTypedConnectionPath(): void
    {
        $sent = [];
        $connection = new PlayerConnection(
            static fn(): bool => true,
            static function (Packet $packet, bool $immediate) use (&$sent): bool {
                $sent[] = [$packet, $immediate];

                return true;
            },
        );
        $player = self::player($connection);

        self::assertTrue($player->connection()->isConnected());
        self::assertTrue($player->sendMessage('message'));
        self::assertTrue($player->sendMessage(new TranslatableMessage('translation.key', ['value'])));
        self::assertTrue($player->sendPopup('popup'));
        self::assertTrue($player->sendJukeboxPopup('jukebox'));
        self::assertTrue($player->sendTip('tip'));
        self::assertTrue($player->sendTitle('title', 'subtitle', new TitleTimes(10, 70, 20)));
        self::assertTrue($player->sendSubTitle('subtitle only'));
        self::assertTrue($player->sendActionBar('action'));
        self::assertTrue($player->setTitleTimes(new TitleTimes(1, 2, 3)));
        self::assertTrue($player->clearTitle());
        self::assertTrue($player->resetTitles());
        self::assertTrue($player->sendToast('toast', 'body'));

        self::assertSame(TextPacketType::Raw, self::text($sent[0][0])->type);
        self::assertInstanceOf(TranslatedTextPacket::class, $sent[1][0]);
        self::assertSame(TextPacketType::Popup, self::text($sent[2][0])->type);
        self::assertSame(TextPacketType::JukeboxPopup, self::text($sent[3][0])->type);
        self::assertSame(TextPacketType::Tip, self::text($sent[4][0])->type);
        self::assertSame(SetTitleType::Times, self::title($sent[5][0])->type);
        self::assertSame(SetTitleType::Subtitle, self::title($sent[6][0])->type);
        self::assertSame(SetTitleType::Title, self::title($sent[7][0])->type);
        self::assertSame(SetTitleType::Subtitle, self::title($sent[8][0])->type);
        self::assertSame(SetTitleType::ActionBar, self::title($sent[9][0])->type);
        self::assertSame(SetTitleType::Times, self::title($sent[10][0])->type);
        self::assertSame(SetTitleType::Clear, self::title($sent[11][0])->type);
        self::assertSame(SetTitleType::Reset, self::title($sent[12][0])->type);
        self::assertInstanceOf(ToastRequestPacket::class, $sent[13][0]);
        self::assertSame(array_fill(0, 14, false), array_column($sent, 1));
    }

    public function testDirectPacketSendCarriesTheImmediateFlag(): void
    {
        $captured = null;
        $connection = new PlayerConnection(
            static fn(): bool => true,
            static function (Packet $packet, bool $immediate) use (&$captured): bool {
                $captured = [$packet, $immediate];

                return true;
            },
        );
        $packet = TextPacket::tip('direct');

        self::assertTrue($connection->sendPacket($packet, immediate: true));
        self::assertSame([$packet, true], $captured);
    }

    public function testDetachedPlayerConnectionIsClosedAndRejectsPackets(): void
    {
        $player = self::player();

        self::assertFalse($player->connection()->isConnected());
        self::assertFalse($player->sendTip('offline'));
        self::assertFalse($player->connection()->sendPacket(TextPacket::raw('offline'), true));
        self::assertFalse($player->kick('offline'));
    }

    public function testPlayerKickForwardsReasonAndMessagesToCurrentConnection(): void
    {
        $captured = null;
        $connection = new PlayerConnection(
            static fn(): bool => true,
            static fn(Packet $packet, bool $immediate): bool => true,
            static function (string $reason, ?string $quit, ?string $screen) use (&$captured): bool {
                $captured = [$reason, $quit, $screen];
                return true;
            },
        );
        self::assertTrue(self::player($connection)->kick('Reason', 'Quit', 'Screen'));
        self::assertSame(['Reason', 'Quit', 'Screen'], $captured);
    }

    public function testTitleTimesAreBounded(): void
    {
        foreach ([[-1, 0, 0], [0, -1, 0], [0, 0, -1], [72_001, 0, 0]] as [$fadeIn, $stay, $fadeOut]) {
            try {
                new TitleTimes($fadeIn, $stay, $fadeOut);
                self::fail('Invalid title timing was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function player(?PlayerConnection $connection = null): Player
    {
        return new Player(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            playerConnection: $connection,
        );
    }

    private static function text(Packet $packet): TextPacket
    {
        self::assertInstanceOf(TextPacket::class, $packet);

        return $packet;
    }

    private static function title(Packet $packet): SetTitlePacket
    {
        self::assertInstanceOf(SetTitlePacket::class, $packet);

        return $packet;
    }
}
