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

final class EntityPersistenceLimits
{
    public const int MAX_DOCUMENT_BYTES = 4_194_304;
    public const int MAX_RECORD_BYTES = 131_072;
    public const int MAX_RECORDS = 4_096;
    public const int MAX_CUSTOM_DATA_BYTES = 65_536;
    public const int MAX_ITEM_DATA_BYTES = 32_768;
    public const int MAX_EQUIPMENT_ENTRIES = 6;
    public const int MAX_WORLD_NAME_BYTES = 128;
    public const int MAX_IDENTIFIER_BYTES = 256;
    public const int MAX_VARIANT_BYTES = 128;

    private function __construct() {}
}
