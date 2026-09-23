<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\World\Provider\WorldData;
use InvalidArgumentException;

final readonly class WorldStorageStartup
{
    public function __construct(
        public string $mode,
        public string $path,
        public ?WorldData $createData = null,
        public ?int $createdAt = null,
    ) {
        if (!in_array($mode, ['open', 'create'], true) || $path === '' || strlen($path) > 4_096
            || str_contains($path, "\0") || (($mode === 'create') !== ($createData !== null && $createdAt !== null))
            || ($createdAt !== null && $createdAt < 0)) {
            throw new InvalidArgumentException('World storage startup value is invalid.');
        }
    }
}
