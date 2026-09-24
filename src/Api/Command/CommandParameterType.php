<?php

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
