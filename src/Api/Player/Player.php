<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Position;
use Bedriox\Protocol\Packet\SetTitlePacket;
use Bedriox\Protocol\Packet\TextPacket;
use Bedriox\Protocol\Packet\ToastRequestPacket;
use Bedriox\Protocol\Packet\TranslatedTextPacket;

/** An immutable snapshot of a connected player. */
final readonly class Player
{
    public function __construct(
        public string $name,
        public string $uuid,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public bool $sneaking,
        public bool $sprinting,
        public Inventory $inventory,
        public float $health = 20.0,
        public float $maxHealth = 20.0,
        public bool $alive = true,
        private ?PlayerConnection $playerConnection = null,
    ) {}

    public function connection(): PlayerConnection
    {
        return $this->playerConnection ?? PlayerConnection::disconnected();
    }

    public function sendMessage(string|TranslatableMessage $message): bool
    {
        return $this->connection()->sendPacket($message instanceof TranslatableMessage
            ? new TranslatedTextPacket($message->key, $message->parameters)
            : TextPacket::raw($message));
    }

    public function sendPopup(string $message): bool
    {
        return $this->connection()->sendPacket(TextPacket::popup($message));
    }

    public function sendJukeboxPopup(string|TranslatableMessage $message): bool
    {
        return $this->connection()->sendPacket($message instanceof TranslatableMessage
            ? TextPacket::jukeboxPopup($message->key, $message->parameters)
            : TextPacket::jukeboxPopup($message));
    }

    public function sendTip(string $message): bool
    {
        return $this->connection()->sendPacket(TextPacket::tip($message));
    }

    public function sendTitle(string $title, string $subtitle = '', ?TitleTimes $times = null): bool
    {
        $connection = $this->connection();
        if ($times !== null && !$connection->sendPacket(SetTitlePacket::times(
            $times->fadeIn,
            $times->stay,
            $times->fadeOut,
        ))) {
            return false;
        }
        if ($subtitle !== '' && !$connection->sendPacket(SetTitlePacket::subtitle($subtitle))) {
            return false;
        }

        return $connection->sendPacket(SetTitlePacket::title($title));
    }

    public function sendSubTitle(string $subtitle): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::subtitle($subtitle));
    }

    public function sendActionBar(string $message): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::actionBar($message));
    }

    public function setTitleTimes(TitleTimes $times): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::times($times->fadeIn, $times->stay, $times->fadeOut));
    }

    public function clearTitle(): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::clear());
    }

    public function resetTitles(): bool
    {
        return $this->connection()->sendPacket(SetTitlePacket::reset());
    }

    public function sendToast(string $title, string $body): bool
    {
        return $this->connection()->sendPacket(new ToastRequestPacket($title, $body));
    }
}
