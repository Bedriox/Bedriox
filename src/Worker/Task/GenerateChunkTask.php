<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Task;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferException;
use Bedriox\Server\Worker\WorkerTaskHandler;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generator\GeneratorContext;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\GeneratorRegistry;
use Bedriox\Server\World\Generator\PluginWorldGeneratorAdapter;
use Bedriox\Server\World\VersionedWorldGenerator;
use Bedriox\Server\World\WorldGeneratorFactory;

final class GenerateChunkTask implements WorkerTaskHandler
{
    private const int MAXIMUM_CACHED_GENERATORS = 8;

    private ?BlockStateRegistry $states = null;
    /** @var array<string, VersionedWorldGenerator> */
    private array $generators = [];
    /** @var list<string> */
    private array $generatorOrder = [];

    private readonly GeneratorRegistry $registry;

    public function __construct(?GeneratorRegistry $registry = null)
    {
        $this->registry = $registry ?? WorldGeneratorFactory::builtIns();
    }

    public function execute(string $payload): string
    {
        $request = (new ChunkGenerationRequestCodec())->decode($payload);
        $states = $this->states ??= new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = $request->workerSource === null
            ? $this->generator(
                $request->generator,
                $request->generatorVersion,
                $request->seed,
                $request->dimension,
                $request->options,
                $states,
            )
            : $this->pluginGenerator($request, $states);

        return (new ChunkTransferCodec())->encode($generator->generate($request->position), $states);
    }

    private function pluginGenerator(
        \Bedriox\Server\Worker\Chunk\ChunkGenerationRequest $request,
        BlockStateRegistry $states,
    ): VersionedWorldGenerator {
        $workerSource = $request->workerSource;
        if ($workerSource === null) {
            throw new ChunkTransferException('Plugin chunk generation requires a worker source identity.');
        }
        $key = $request->generator . ':' . $request->generatorVersion . ':' . $request->seed . ':'
            . $request->dimension . ':' . $request->options->hash() . ':' . $workerSource->sha256;
        $cached = $this->generators[$key] ?? null;
        if ($cached !== null) {
            return $cached;
        }
        $generator = new PluginWorldGeneratorAdapter(
            $request->generator,
            $request->generatorVersion,
            $workerSource->class,
            new GeneratorContext($request->seed, $request->dimension, $request->options, $states),
            $workerSource,
        );
        $this->remember($key, $generator);

        return $generator;
    }

    private function generator(
        string $name,
        int $version,
        int $seed,
        string $dimension,
        GeneratorOptions $options,
        BlockStateRegistry $states,
    ): VersionedWorldGenerator {
        $key = $name . ':' . $version . ':' . $seed . ':' . $dimension . ':' . $options->hash();
        $cached = $this->generators[$key] ?? null;
        if ($cached !== null) {
            return $cached;
        }
        $generator = WorldGeneratorFactory::create($name, $seed, $states, $options, $dimension, $this->registry);
        if ($generator->version() !== $version) {
            throw new ChunkTransferException('Chunk generation request uses an unsupported generator version.');
        }
        $this->remember($key, $generator);

        return $generator;
    }

    private function remember(string $key, VersionedWorldGenerator $generator): void
    {
        $this->generators[$key] = $generator;
        $this->generatorOrder[] = $key;
        if (count($this->generatorOrder) > self::MAXIMUM_CACHED_GENERATORS) {
            $expired = array_shift($this->generatorOrder);
            unset($this->generators[$expired]);
        }
    }
}
