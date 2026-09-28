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

namespace Bedriox\Api\Command;

enum CommandParameterType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case ONLINE_PLAYER = 'online_player';
    case PLAYERS = 'players';
    case ENTITY = 'entity';
    case ENTITIES = 'entities';
    case CHOICE = 'choice';
    case ENUM = 'enum';
    case SOFT_ENUM = 'soft_enum';
    case POSITION = 'position';
    case BLOCK_POSITION = 'block_position';
    case MESSAGE = 'message';
    case JSON = 'json';
    case RAW_TEXT = 'raw_text';
    case LITERAL = 'literal';

    public function isGreedy(): bool
    {
        return $this === self::MESSAGE || $this === self::JSON || $this === self::RAW_TEXT;
    }

    public function isNumeric(): bool
    {
        return $this === self::INTEGER || $this === self::FLOAT;
    }
}
