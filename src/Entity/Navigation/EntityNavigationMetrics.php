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

namespace Bedriox\Server\Entity\Navigation;

final readonly class EntityNavigationMetrics
{
    public function __construct(
        public int $submitted,
        public int $completed,
        public int $rejected,
        public int $pending,
        public int $cached,
        public int $building = 0,
        public int $buildsQueued = 0,
        public int $buildsCompleted = 0,
        public int $cellsScanned = 0,
        public int $preparationNanoseconds = 0,
        public int $maximumPreparationNanoseconds = 0,
        public int $pathsReused = 0,
        public int $repathsSuppressed = 0,
    ) {}
}
