<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable intent emitted before the authoritative player mode changes. */
final class PlayerGameModeChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly GameMode $previous,
        private GameMode $gameMode,
    ) {}

    public function gameMode(): GameMode
    {
        return $this->gameMode;
    }

    public function setGameMode(GameMode $gameMode): void
    {
        $this->assertMutable();
        $this->gameMode = $gameMode;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->gameMode];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !$state[1] instanceof GameMode) {
            throw new InvalidArgumentException('Invalid player gamemode event state.');
        }
        parent::replaceState($state[0]);
        $this->gameMode = $state[1];
    }
}
