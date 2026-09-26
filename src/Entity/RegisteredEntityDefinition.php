<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Closure;
use InvalidArgumentException;

/** @internal One immutable definition/factory pair owned by the entity runtime. */
final readonly class RegisteredEntityDefinition
{
    /**
     * @param Closure(string, int, string, \Bedriox\Server\Simulation\Position, float, float): AbstractEntity $factory
     */
    public function __construct(
        public EntityDefinition $definition,
        public Closure $factory,
        public ?string $owner = null,
    ) {
        if ($owner !== null && ($owner === '' || strlen($owner) > 128 || preg_match('//u', $owner) !== 1)) {
            throw new InvalidArgumentException('Entity-definition owner must be valid UTF-8 and bounded.');
        }
    }
}
