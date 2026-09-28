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

namespace Bedriox\Server\World\Generator;

use Bedriox\Server\World\VersionedWorldGenerator;
use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use RuntimeException;

final readonly class GeneratorDefinition
{
    /** @var Closure(GeneratorContext): VersionedWorldGenerator */
    private Closure $factory;

    /** @param Closure(GeneratorContext): VersionedWorldGenerator $factory */
    public function __construct(
        public GeneratorIdentifier $identifier,
        public int $version,
        public string $owner,
        Closure $factory,
        public GeneratorExecution $execution = GeneratorExecution::WORKER,
        public ?WorkerGeneratorSource $workerSource = null,
    ) {
        if ($version < 1 || $version > 65_535) {
            throw new InvalidArgumentException('Generator version must be between 1 and 65535.');
        }
        if (!mb_check_encoding($owner, 'UTF-8') || strlen($owner) < 1 || strlen($owner) > 128) {
            throw new InvalidArgumentException('Generator owner must be valid UTF-8 and bounded.');
        }
        $reflection = new ReflectionFunction($factory);
        if (!$reflection->isStatic()) {
            throw new InvalidArgumentException('Generator factories must be static and independent of live server state.');
        }
        try {
            new GeneratorOptions($reflection->getStaticVariables());
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'Generator factory captures must contain only bounded worker-safe values.',
                previous: $exception,
            );
        }
        $this->factory = $factory;
        if ($workerSource !== null && $execution !== GeneratorExecution::WORKER) {
            throw new InvalidArgumentException('Worker generator source requires worker execution.');
        }
    }

    public function create(GeneratorContext $context): VersionedWorldGenerator
    {
        $generator = ($this->factory)($context);
        if ($generator->name() !== $this->identifier->value || $generator->version() !== $this->version) {
            throw new RuntimeException('Generator factory returned metadata that does not match its definition.');
        }

        return $generator;
    }
}
