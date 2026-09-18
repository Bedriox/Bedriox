<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Server\Simulation\PlayerSnapshot;
use LogicException;

/** World-owned player indexes with deterministic iteration and complete removal. */
final class PlayerRegistry
{
    /** @var array<string, Player> */
    private array $bySession = [];

    /** @var array<string, string> identity UUID => session ID */
    private array $sessionByIdentity = [];

    /** @var array<int, string> runtime actor ID => session ID */
    private array $sessionByActorId = [];

    public function __construct(private readonly int $capacity) {}

    public function count(): int
    {
        return count($this->bySession);
    }

    public function isFull(): bool
    {
        return $this->count() >= $this->capacity;
    }

    public function hasSession(string $sessionId): bool
    {
        return isset($this->bySession[self::sessionKey($sessionId)]);
    }

    public function hasIdentity(string $identity): bool
    {
        return isset($this->sessionByIdentity[self::identityKey($identity)]);
    }

    public function player(string $sessionId): ?Player
    {
        return $this->bySession[self::sessionKey($sessionId)] ?? null;
    }

    public function playerByIdentity(string $identity): ?Player
    {
        $sessionId = $this->sessionByIdentity[self::identityKey($identity)] ?? null;

        return $sessionId === null ? null : $this->player($sessionId);
    }

    public function hasActorId(int $runtimeActorId): bool
    {
        return isset($this->sessionByActorId[$runtimeActorId]);
    }

    public function add(Player $player): void
    {
        if ($this->hasSession($player->sessionId)
            || $this->hasIdentity($player->identity->uuid)
            || $this->hasActorId($player->runtimeActorId)
            || $this->isFull()) {
            throw new LogicException('Player cannot be added to this registry.');
        }
        $this->bySession[self::sessionKey($player->sessionId)] = $player;
        $this->sessionByIdentity[self::identityKey($player->identity->uuid)] = $player->sessionId;
        $this->sessionByActorId[$player->runtimeActorId] = $player->sessionId;
    }

    public function remove(string $sessionId): ?Player
    {
        $key = self::sessionKey($sessionId);
        $player = $this->bySession[$key] ?? null;
        if ($player === null) {
            return null;
        }
        unset(
            $this->bySession[$key],
            $this->sessionByIdentity[self::identityKey($player->identity->uuid)],
            $this->sessionByActorId[$player->runtimeActorId],
        );

        return $player;
    }

    /** @return list<string> */
    public function recipients(?string $excludedSessionId = null): array
    {
        $recipients = [];
        foreach ($this->bySession as $player) {
            if ($player->sessionId !== $excludedSessionId) {
                $recipients[] = $player->sessionId;
            }
        }
        sort($recipients, SORT_STRING);

        return $recipients;
    }

    /** @return list<PlayerSnapshot> */
    public function snapshots(): array
    {
        $players = $this->bySession;
        ksort($players, SORT_STRING);

        return array_values(array_map(static fn(Player $player): PlayerSnapshot => $player->snapshot(), $players));
    }

    private static function sessionKey(string $sessionId): string
    {
        return 'session:' . $sessionId;
    }

    private static function identityKey(string $identity): string
    {
        return 'identity:' . $identity;
    }
}
