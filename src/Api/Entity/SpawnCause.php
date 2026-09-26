<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

enum SpawnCause: string
{
    case SPAWN_EGG = 'spawn_egg';
    case COMMAND = 'command';
    case PLUGIN = 'plugin';
    case NATURAL = 'natural';
    case SPAWNER = 'spawner';
    case BREEDING = 'breeding';
    case STRUCTURE = 'structure';
    case CHUNK_LOAD = 'chunk_load';
}
