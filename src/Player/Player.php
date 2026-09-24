<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;
use InvalidArgumentException;
use OverflowException;

/** Authoritative mutable player aggregate; it deliberately has no packet or socket API. */
final class Player
{
    public int $chatSequence = -1;
    public int $chatTokens;
    public int $lastChatRefillTick;
    public ?int $lastEmoteTick = null;
    public int $placementSequence = -1;
    public readonly PlayerMovement $movement;
    public readonly PlayerInventory $inventory;
    public readonly PlayerVitals $vitals;
    public readonly string $worldName;
    public readonly int $firstPlayedAt;
    /** Persisted canonical value; mutate only through setGameMode(). */
    public string $gamemode;
    private int $stateRevision = 0;
    private int $savedRevision = 0;

    public function __construct(
        public readonly string $sessionId,
        public readonly int $runtimeActorId,
        public readonly PlayerIdentity $identity,
        Position $spawn,
        int $chatTokens,
        int $tick,
        float $groundY,
        ?PlayerInventory $inventory = null,
        string $worldName = 'world',
        int $firstPlayedAt = 0,
        string $gamemode = 'survival',
        float $health = PlayerVitals::MAX_HEALTH,
        float $food = PlayerVitals::MAX_FOOD,
        float $saturation = PlayerVitals::MAX_SATURATION,
        float $exhaustion = 0.0,
    ) {
        $this->chatTokens = $chatTokens;
        $this->lastChatRefillTick = $tick;
        $this->movement = new PlayerMovement(
            $spawn,
            $spawn->y <= $groundY ? VerticalState::GROUNDED : VerticalState::AIRBORNE,
            $tick,
            $tick,
        );
        $this->inventory = $inventory ?? PlayerInventory::empty();
        $this->worldName = $worldName;
        $this->firstPlayedAt = $firstPlayedAt;
        $this->gamemode = GameMode::from($gamemode)->value;
        $this->vitals = new PlayerVitals($health, $food, $saturation, $exhaustion);
    }

    public function snapshot(): PlayerSnapshot
    {
        return new PlayerSnapshot(
            $this->sessionId,
            $this->identity->uuid,
            $this->identity->displayName,
            $this->movement->position,
            $this->movement->yaw,
            $this->movement->pitch,
            $this->movement->mode,
            $this->movement->sequence,
            $this->movement->verticalState,
            $this->movement->verticalVelocity,
            $this->runtimeActorId,
            $this->movement->headYaw,
            $this->movement->sneaking,
            $this->movement->sprinting,
            $this->vitals->health,
            $this->vitals->isAlive(),
            $this->gameMode(),
            $this->vitals->food,
            $this->vitals->saturation,
            $this->vitals->exhaustion,
            $this->inventory->armorSlots(),
            $this->inventory->offhandStack(),
            $this->inventory->selectedHotbarSlot(),
            $this->inventory->selectedStack(),
        );
    }

    public function gameMode(): GameMode
    {
        return GameMode::from($this->gamemode);
    }

    public function setGameMode(GameMode $gameMode): GameMode
    {
        $previous = $this->gameMode();
        if ($previous !== $gameMode) {
            $this->gamemode = $gameMode->value;
            $this->markDirty();
        }

        return $previous;
    }

    public function stateRevision(): int
    {
        return $this->stateRevision;
    }

    public function savedRevision(): int
    {
        return $this->savedRevision;
    }

    public function isDirty(): bool
    {
        return $this->stateRevision !== $this->savedRevision;
    }

    public function markDirty(): int
    {
        if ($this->stateRevision === PHP_INT_MAX) {
            throw new OverflowException('Player state revision space is exhausted.');
        }

        return ++$this->stateRevision;
    }

    /** Acknowledges exactly the state revision durably written by a persistence owner. */
    public function acknowledgeSaved(int $revision): bool
    {
        if ($revision < 0 || $revision > $this->stateRevision) {
            throw new InvalidArgumentException('Saved player revision is outside the authoritative revision range.');
        }
        if ($revision <= $this->savedRevision) {
            return false;
        }
        $this->savedRevision = $revision;

        return true;
    }
}
