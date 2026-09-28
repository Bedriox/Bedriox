<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

interface WorldManager
{
    public function getDefault(): World;

    public function get(string $worldId): ?World;

    /** @return list<World> */
    public function getLoaded(): array;

    public function getState(string $worldId): ?WorldLifecycleState;

    public function info(World $world): WorldInfo;

    public function create(
        string $worldId,
        WorldCreationOptions $options = new WorldCreationOptions(),
    ): WorldOperation;

    public function load(string $worldId): WorldOperation;

    public function save(World $world): WorldOperation;

    public function unload(
        World $world,
        WorldUnloadOptions $options = new WorldUnloadOptions(),
    ): WorldOperation;
}
