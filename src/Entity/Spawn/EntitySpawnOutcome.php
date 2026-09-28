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

namespace Bedriox\Server\Entity\Spawn;

use Bedriox\Server\Entity\AbstractEntity;

final readonly class EntitySpawnOutcome
{
    private function __construct(
        public ?AbstractEntity $entity,
        public ?string $failure,
    ) {}

    public static function success(AbstractEntity $entity): self
    {
        return new self($entity, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->entity !== null;
    }
}
