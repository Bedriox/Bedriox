<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Closure;
use InvalidArgumentException;

/** @internal One immutable definition/factory pair owned by the entity runtime. */
final readonly class RegisteredEntityDefinition
{
    /**
     * @param Closure(string, int, string, \Bedriox\Server\Simulation\Position, float, float): AbstractEntity $factory
     * @param null|Closure(string, int, \Bedriox\Server\Entity\Persistence\EntityPersistenceRecord): AbstractEntity $persistenceFactory
     * @param null|Closure(string, int, string, \Bedriox\Server\Simulation\Position, float, float, int|string): AbstractEntity $variantFactory
     */
    public function __construct(
        public EntityDefinition $definition,
        public Closure $factory,
        public ?string $owner = null,
        public ?Closure $persistenceFactory = null,
        public ?Closure $variantFactory = null,
    ) {
        if ($owner !== null && ($owner === '' || strlen($owner) > 128 || preg_match('//u', $owner) !== 1)) {
            throw new InvalidArgumentException('Entity-definition owner must be valid UTF-8 and bounded.');
        }
    }
}
