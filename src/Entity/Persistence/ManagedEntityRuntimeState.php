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

namespace Bedriox\Server\Entity\Persistence;

/** @internal */
final class ManagedEntityRuntimeState
{
    public function __construct(
        public EntityPersistenceRecord $record,
        public readonly int $runtimeId,
        public int $runtimeRevisionBaseline,
        public int $runtimeAgeBaseline,
        public int $persistedAgeBaseline,
        public bool $durablyStored,
    ) {}
}
