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

namespace Bedriox\Server\Entity\Experience;

/** Authoritative award produced when a player collects one orb from an aggregate actor. */
final readonly class ExperienceOrbPickupResult
{
    public function __construct(
        public int $runtimeEntityId,
        public string $collectorSessionId,
        public int $collectorRuntimeActorId,
        public int $awardedExperience,
        public bool $removed,
        public ?ExperienceOrbEntity $remaining,
    ) {}
}
