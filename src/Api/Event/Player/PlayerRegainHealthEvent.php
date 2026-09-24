<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\HealthRegainCause;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable health restoration emitted before authoritative commit. */
final class PlayerRegainHealthEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly HealthRegainCause $cause,
        private float $amount,
    ) {
        self::validateAmount($amount);
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->assertMutable();
        self::validateAmount($amount);
        $this->amount = $amount;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->amount];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_float($state[1])) {
            throw new InvalidArgumentException('Invalid player regain-health event state.');
        }
        parent::replaceState($state[0]);
        self::validateAmount($state[1]);
        $this->amount = $state[1];
    }

    private static function validateAmount(float $amount): void
    {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Health restoration must be finite and inside its bounded range.');
        }
    }
}
