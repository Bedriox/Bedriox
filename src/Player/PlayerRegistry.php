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

    /** @var list<string>|null */
    private ?array $orderedSessionIds = null;

    /** @var array<string, list<string>> */
    private array $recipientLists = [];

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

    public function playerByActorId(int $runtimeActorId): ?Player
    {
        $sessionId = $this->sessionByActorId[$runtimeActorId] ?? null;

        return $sessionId === null ? null : $this->player($sessionId);
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
        $this->orderedSessionIds = null;
        $this->recipientLists = [];
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
        $this->orderedSessionIds = null;
        $this->recipientLists = [];

        return $player;
    }

    /** @return list<string> */
    public function recipients(?string $excludedSessionId = null): array
    {
        $ordered = $this->orderedSessionIds;
        if ($ordered === null) {
            $ordered = array_map(
                static fn(Player $player): string => $player->sessionId,
                array_values($this->bySession),
            );
            sort($ordered, SORT_STRING);
            $this->orderedSessionIds = $ordered;
        }
        if ($excludedSessionId === null) {
            return $ordered;
        }
        $cacheKey = self::sessionKey($excludedSessionId);
        if (isset($this->recipientLists[$cacheKey])) {
            return $this->recipientLists[$cacheKey];
        }
        $recipients = [];
        foreach ($ordered as $sessionId) {
            if ($sessionId !== $excludedSessionId) {
                $recipients[] = $sessionId;
            }
        }

        return $this->recipientLists[$cacheKey] = $recipients;
    }

    /** @return list<PlayerSnapshot> */
    public function snapshots(): array
    {
        $players = $this->bySession;
        ksort($players, SORT_STRING);

        return array_values(array_map(static fn(Player $player): PlayerSnapshot => $player->snapshot(), $players));
    }

    /** @return list<Player> */
    public function players(): array
    {
        $players = $this->bySession;
        ksort($players, SORT_STRING);

        return array_values($players);
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
