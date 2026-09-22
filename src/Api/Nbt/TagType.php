<?php

declare(strict_types=1);

namespace Bedriox\Api\Nbt;

enum TagType: int
{
    case BYTE = 1;
    case SHORT = 2;
    case INT = 3;
    case LONG = 4;
    case FLOAT = 5;
    case DOUBLE = 6;
    case BYTE_ARRAY = 7;
    case STRING = 8;
    case LIST = 9;
    case COMPOUND = 10;
    case INT_ARRAY = 11;
    case LONG_ARRAY = 12;
}
