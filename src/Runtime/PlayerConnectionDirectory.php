<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Protocol\Packet\Packet;
use Closure;

/** @internal Binds immutable public player handles to the current runtime session without exposing it. */
final class PlayerConnectionDirectory
{
    /** @var array<string, array{session: string, connected: Closure(): bool, send: Closure(Packet, bool): bool, kick: Closure(string, ?string, ?string): bool, swing: Closure(): bool}> */
    private array $connections = [];

    /**
     * @param Closure(): bool             $connected
     * @param Closure(Packet, bool): bool $send
     * @param Closure(string, ?string, ?string): bool $kick
     * @param Closure(): bool $swingArm
     */
    public function connect(
        string $identity,
        string $sessionId,
        Closure $connected,
        Closure $send,
        ?Closure $kick = null,
        ?Closure $swingArm = null,
    ): void {
        $this->connections[self::key($identity)] = [
            'session' => $sessionId,
            'connected' => $connected,
            'send' => $send,
            'kick' => $kick ?? static fn(string $reason, ?string $quitMessage, ?string $screenMessage): bool => false,
            'swing' => $swingArm ?? static fn(): bool => false,
        ];
    }

    public function disconnect(string $identity, string $sessionId): void
    {
        $key = self::key($identity);
        if (($this->connections[$key]['session'] ?? null) === $sessionId) {
            unset($this->connections[$key]);
        }
    }

    public function connection(string $identity): PlayerConnection
    {
        $key = self::key($identity);

        return new PlayerConnection(
            fn(): bool => isset($this->connections[$key]) && ($this->connections[$key]['connected'])(),
            fn(Packet $packet, bool $immediate): bool => isset($this->connections[$key])
                && ($this->connections[$key]['send'])($packet, $immediate),
            fn(string $reason, ?string $quitMessage, ?string $screenMessage): bool => isset($this->connections[$key])
                && ($this->connections[$key]['kick'])($reason, $quitMessage, $screenMessage),
            fn(): bool => isset($this->connections[$key])
                && ($this->connections[$key]['swing'])(),
        );
    }

    /** Rebinds an immutable public snapshot to its current runtime connection. */
    public function attach(Player $player): Player
    {
        return new Player(
            $player->name,
            $player->uuid,
            $player->position,
            $player->yaw,
            $player->pitch,
            $player->sneaking,
            $player->sprinting,
            $player->inventory,
            $player->health,
            $player->maxHealth,
            $player->alive,
            $player->gameMode,
            $this->connection($player->uuid),
        );
    }

    private static function key(string $identity): string
    {
        return strtolower($identity);
    }
}
