<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

use Bedriox\Protocol\Packet\Packet;
use Closure;

/** A live, typed packet path to one player's current Bedrock connection. */
final readonly class PlayerConnection
{
    /**
     * @param Closure(): bool              $connected
     * @param Closure(Packet, bool): bool  $sendPacket
     * @param Closure(string, ?string, ?string): bool $kick
     * @internal The server owns connection construction; plugins receive it from Player::connection().
     */
    public function __construct(
        private Closure $connected,
        private Closure $sendPacket,
        private ?Closure $kick = null,
    ) {}

    public static function disconnected(): self
    {
        return new self(
            static fn(): bool => false,
            static fn(Packet $packet, bool $immediate): bool => false,
            static fn(string $reason, ?string $quitMessage, ?string $screenMessage): bool => false,
        );
    }

    public function isConnected(): bool
    {
        return ($this->connected)();
    }

    public function sendPacket(Packet $packet, bool $immediate = false): bool
    {
        return ($this->sendPacket)($packet, $immediate);
    }

    public function kick(string $reason = '', ?string $quitMessage = null, ?string $disconnectScreenMessage = null): bool
    {
        return $this->kick !== null && ($this->kick)($reason, $quitMessage, $disconnectScreenMessage);
    }
}
