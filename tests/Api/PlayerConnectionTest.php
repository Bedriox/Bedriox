<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\PlayerInventoryActions;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerActions;
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
use LogicException;
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
            swingArm: static fn(): bool => true,
        );
        $player = self::player($connection);

        self::assertTrue($player->isConnected());
        self::assertTrue($player->connection()->isConnected());
        self::assertTrue($player->swingArm());
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

        self::assertFalse($player->isConnected());
        self::assertFalse($player->connection()->isConnected());
        self::assertFalse($player->swingArm());
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

    public function testAuthoritativePlayerMethodsDelegateToAttachedActions(): void
    {
        $received = [];
        $actions = new PlayerActions(
            static function (Position $position) use (&$received): void {
                $received[] = ['teleport', $position];
            },
            static function (GameMode $gameMode) use (&$received): void {
                $received[] = ['gamemode', $gameMode];
            },
            static function (float $amount) use (&$received): void {
                $received[] = ['damage', $amount];
            },
        );
        $inventoryActions = self::inventoryActions($received);
        $player = self::player()->withRuntime(
            PlayerConnection::disconnected(),
            $actions,
            $inventoryActions,
            static fn(ItemStack $stack): int => $stack->identifier === 'minecraft:iron_pickaxe' ? 1 : 64,
        );
        $position = new Position(4.0, 70.0, -2.0);
        $stack = new ItemStack('minecraft:stone', 3);

        $player->teleport($position);
        $player->setGameMode(GameMode::CREATIVE);
        $player->getInventory()->addItem($stack);
        $player->getInventory()->setItem(4, $stack);
        $player->damage(3.5);

        self::assertSame([
            ['teleport', $position],
            ['gamemode', GameMode::CREATIVE],
            ['main.add', $stack],
            ['main.set', 4, $stack],
            ['damage', 3.5],
        ], $received);
    }

    public function testPlayerInventoryViewsExposeImmutableSnapshots(): void
    {
        $stone = new ItemStack('minecraft:stone', 3);
        $helmet = new ItemStack('minecraft:iron_helmet', 1);
        $shield = new ItemStack('minecraft:shield', 1);
        $slots = array_fill(0, 36, null);
        $slots[2] = $stone;
        $player = self::player(
            inventory: new Inventory($slots, 2),
            armor: [
                EquipmentSlot::HEAD->value => $helmet,
                EquipmentSlot::CHEST->value => null,
                EquipmentSlot::LEGS->value => null,
                EquipmentSlot::FEET->value => null,
            ],
            offHand: $shield,
        );

        self::assertSame(36, $player->getInventory()->getSize());
        self::assertSame(2, $player->getInventory()->getSelectedHotbarSlot());
        self::assertSame($stone, $player->getInventory()->getHeldItem());
        self::assertSame($stone, $player->getInventory()->getItem(2));
        self::assertTrue($player->getInventory()->contains(new ItemStack('minecraft:stone', 2)));
        self::assertFalse($player->getInventory()->isEmpty());
        self::assertSame(0, $player->getInventory()->firstEmpty());
        self::assertSame($helmet, $player->getArmorInventory()->getHelmet());
        self::assertSame($shield, $player->getOffHandInventory()->getItem());

        $contents = $player->getInventory()->getContents();
        $contents[2] = null;
        self::assertSame($stone, $player->getInventory()->getItem(2));
    }

    public function testInventoryMutationsDelegateThroughSeparateCapabilities(): void
    {
        $received = [];
        $stack = new ItemStack('minecraft:stone', 3);
        $player = self::player()->withRuntime(
            PlayerConnection::disconnected(),
            new PlayerActions(static fn(Position $position) => null, static fn(GameMode $mode) => null, static fn(float $amount) => null),
            self::inventoryActions($received),
            static fn(ItemStack $item): int => 64,
        );

        $player->getInventory()->setContents(array_fill(0, 36, null));
        $player->getInventory()->removeItem($stack);
        $player->getInventory()->setSelectedHotbarSlot(4);
        $player->getInventory()->clear(3);
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->setHelmet($stack);
        $player->getArmorInventory()->clear(EquipmentSlot::FEET);
        $player->getArmorInventory()->clearAll();
        $player->getOffHandInventory()->setItem($stack);
        $player->getOffHandInventory()->clear();

        self::assertCount(10, $received);
        self::assertSame('main.contents', $received[0][0]);
        self::assertSame(['main.remove', $stack], $received[1]);
        self::assertSame(['main.select', 4], $received[2]);
        self::assertSame(['main.set', 3, null], $received[3]);
        self::assertSame('main.contents', $received[4][0]);
        self::assertSame(['armor.set', EquipmentSlot::HEAD, $stack], $received[5]);
        self::assertSame(['armor.set', EquipmentSlot::FEET, null], $received[6]);
        self::assertSame(['armor.contents', [
            EquipmentSlot::HEAD->value => null,
            EquipmentSlot::CHEST->value => null,
            EquipmentSlot::LEGS->value => null,
            EquipmentSlot::FEET->value => null,
        ]], $received[7]);
        self::assertSame(['offhand.set', $stack], $received[8]);
        self::assertSame(['offhand.set', null], $received[9]);
    }

    public function testAddableQuantityUsesAuthoritativeItemStackRules(): void
    {
        $slots = array_fill(0, 36, null);
        $slots[0] = new ItemStack('minecraft:iron_pickaxe', 1);
        $unused = [];
        $player = self::player(inventory: new Inventory($slots, 0))->withRuntime(
            PlayerConnection::disconnected(),
            new PlayerActions(static fn(Position $position) => null, static fn(GameMode $mode) => null, static fn(float $amount) => null),
            self::inventoryActions($unused),
            static fn(ItemStack $stack): int => $stack->identifier === 'minecraft:iron_pickaxe' ? 1 : 64,
        );

        self::assertSame(35, $player->getInventory()->getAddableQuantity(new ItemStack('minecraft:iron_pickaxe', 1)));
    }

    public function testDetachedPlayerRejectsAuthoritativeMutation(): void
    {
        $this->expectException(LogicException::class);
        self::player()->damage(1.0);
    }

    public function testDetachedPlayerInventoryRejectsAuthoritativeMutation(): void
    {
        $this->expectException(LogicException::class);
        self::player()->getInventory()->clearAll();
    }

    public function testSupersededDirectInventorySurfaceIsAbsent(): void
    {
        $player = new \ReflectionClass(Player::class);
        self::assertFalse($player->hasProperty('inventory'));
        self::assertFalse($player->hasMethod('giveItem'));
        self::assertFalse($player->hasMethod('setInventorySlot'));
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

    /**
     * @param array<string, ItemStack|null> $armor
     */
    private static function player(
        ?PlayerConnection $connection = null,
        ?Inventory $inventory = null,
        array $armor = [],
        ?ItemStack $offHand = null,
    ): Player {
        return new Player(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            $inventory ?? new Inventory(array_fill(0, 36, null), 0),
            playerConnection: $connection,
            armorInventory: $armor,
            offHandItem: $offHand,
        );
    }

    /** @param array<int, array<mixed>> $received */
    private static function inventoryActions(array &$received): PlayerInventoryActions
    {
        return new PlayerInventoryActions(
            static function (int $slot, ?ItemStack $stack) use (&$received): void {
                $received[] = ['main.set', $slot, $stack];
            },
            static function (array $contents) use (&$received): void {
                $received[] = ['main.contents', $contents];
            },
            static function (ItemStack $stack) use (&$received): void {
                $received[] = ['main.add', $stack];
            },
            static function (ItemStack $stack) use (&$received): void {
                $received[] = ['main.remove', $stack];
            },
            static function (int $slot) use (&$received): void {
                $received[] = ['main.select', $slot];
            },
            static function (EquipmentSlot $slot, ?ItemStack $stack) use (&$received): void {
                $received[] = ['armor.set', $slot, $stack];
            },
            static function (array $contents) use (&$received): void {
                $received[] = ['armor.contents', $contents];
            },
            static function (?ItemStack $stack) use (&$received): void {
                $received[] = ['offhand.set', $stack];
            },
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
