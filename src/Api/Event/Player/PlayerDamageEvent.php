<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

final class PlayerDamageEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly string $cause,
        private float $damage,
    ) {
        self::validateDamage($damage);
    }

    public function damage(): float
    {
        return $this->damage;
    }

    public function setDamage(float $damage): void
    {
        $this->assertMutable();
        self::validateDamage($damage);
        $this->damage = $damage;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->damage];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_float($state[1])) {
            throw new InvalidArgumentException('Invalid player damage event state.');
        }
        parent::replaceState($state[0]);
        $this->damage = $state[1];
    }

    private static function validateDamage(float $damage): void
    {
        if (!is_finite($damage) || $damage < 0.0 || $damage > 1_000_000.0) {
            throw new InvalidArgumentException('Damage must be finite and inside its bounded range.');
        }
    }
}
