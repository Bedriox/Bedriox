<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\World;

use Bedriox\Server\World\Generator\GeneratorIdentifier;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;

/** Immutable input for generator validation and default-spawn calculation in a worker. */
final readonly class WorldPreparationRequest
{
    public function __construct(
        public string $generator,
        public string $generatorIdentifier,
        public int $generatorVersion,
        public int $seed,
        public string $dimension,
        public GeneratorOptions $options = new GeneratorOptions(),
        public ?WorkerGeneratorSource $workerSource = null,
    ) {
        new GeneratorIdentifier($generatorIdentifier);
        if ($generator === '' || strlen($generator) > 96
            || $generatorVersion < 1 || $generatorVersion > 65_535
            || preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $dimension) !== 1) {
            throw new \InvalidArgumentException('World preparation request has unsupported generator metadata.');
        }
    }
}
