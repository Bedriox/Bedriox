<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use InvalidArgumentException;

/** Compact slot-indexed memory; definitions are shared and values remain per entity. */
final class AiMemoryStore
{
    public const int MAXIMUM_MEMORIES = 64;

    /** @var array<int, mixed> */
    private array $values = [];

    /** @var array<int, int> Absolute expiry tick. */
    private array $expiries = [];

    public function contains(AiMemoryType $type, int $tick): bool
    {
        $this->expire($type, $tick);

        return array_key_exists($type->slot, $this->values);
    }

    public function get(AiMemoryType $type, int $tick): mixed
    {
        $this->expire($type, $tick);

        return $this->values[$type->slot] ?? null;
    }

    public function put(AiMemoryType $type, mixed $value, ?int $expiresAtTick = null): void
    {
        if ($expiresAtTick !== null && $expiresAtTick < 0) {
            throw new InvalidArgumentException('AI memory expiry tick cannot be negative.');
        }
        $this->values[$type->slot] = $value;
        if ($expiresAtTick === null) {
            unset($this->expiries[$type->slot]);
        } else {
            $this->expiries[$type->slot] = $expiresAtTick;
        }
    }

    public function forget(AiMemoryType $type): void
    {
        unset($this->values[$type->slot], $this->expiries[$type->slot]);
    }

    public function take(AiMemoryType $type, int $tick): mixed
    {
        $value = $this->get($type, $tick);
        $this->forget($type);

        return $value;
    }

    /**
     * @param list<AiMemoryType> $types
     * @return array<int, mixed>
     */
    public function persistentValues(array $types, int $tick): array
    {
        $result = [];
        foreach ($types as $type) {
            if (!$type->persistent) {
                continue;
            }
            $value = $this->get($type, $tick);
            if ($value !== null) {
                $result[$type->slot] = $value;
            }
        }

        return $result;
    }

    private function expire(AiMemoryType $type, int $tick): void
    {
        $expiry = $this->expiries[$type->slot] ?? null;
        if ($expiry !== null && $tick >= $expiry) {
            $this->forget($type);
        }
    }
}
