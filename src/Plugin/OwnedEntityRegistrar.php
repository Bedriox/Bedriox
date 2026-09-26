<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\CustomMobDefinition;
use Bedriox\Api\Entity\EntityRegistrar;
use Bedriox\Api\World\Position;
use Closure;
use InvalidArgumentException;

final readonly class OwnedEntityRegistrar implements EntityRegistrar
{
    /** @var null|Closure(CustomEntityType, Position, float, float): bool */
    private ?Closure $spawner;

    /** @param null|callable(CustomEntityType, Position, float, float): bool $spawner */
    public function __construct(
        private string $plugin,
        private PluginEntityRegistrar $registrar,
        private ?PluginActionBuffer $actions = null,
        ?callable $spawner = null,
    ) {
        $this->spawner = $spawner === null ? null : Closure::fromCallable($spawner);
    }

    public function register(CustomMobDefinition $definition, bool $replace = false): void
    {
        $this->registrar->register($this->plugin, $definition, $replace);
    }

    public function spawn(CustomEntityType $type, Position $position, float $yaw = 0.0, float $pitch = 0.0): void
    {
        $registration = $this->registrar->definition($type);
        if ($registration === null || strcasecmp($registration->owner, $this->plugin) !== 0) {
            throw new PluginException('Plugin may spawn only custom entity types it currently owns.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0 || !is_finite($yaw) || !is_finite($pitch)
            || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Custom entity spawn transform is outside world bounds.');
        }
        if ($this->spawner === null) {
            throw new PluginException('Custom entity spawning is unavailable.');
        }
        $spawn = function () use ($type, $position, $yaw, $pitch): void {
            if (!(($this->spawner)($type, $position, $yaw, $pitch))) {
                throw new PluginException('The authoritative simulation rejected the custom entity spawn.');
            }
        };
        if ($this->actions?->isCapturing() === true) {
            $this->actions->stage($spawn);
        } else {
            $spawn();
        }
    }
}
