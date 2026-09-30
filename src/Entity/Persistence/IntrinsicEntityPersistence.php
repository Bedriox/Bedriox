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

/** Internal durable state owned by a built-in entity implementation. */
interface IntrinsicEntityPersistence
{
    public function persistenceVariant(): int|string|null;

    public function persistenceSchemaVersion(): int;

    public function persistenceData(): string;

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void;
}
