<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Task;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Worker\Chunk\ChunkGenerationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferException;
use Bedriox\Server\Worker\WorkerTaskHandler;
use Bedriox\Server\World\Block\BlockStateRegistry;
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

    public function execute(string $payload): string
    {
        $request = (new ChunkGenerationRequestCodec())->decode($payload);
        $states = $this->states ??= new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = $this->generator($request->generator, $request->generatorVersion, $request->seed, $states);

        return (new ChunkTransferCodec())->encode($generator->generate($request->position), $states);
    }

    private function generator(
        string $name,
        int $version,
        int $seed,
        BlockStateRegistry $states,
    ): VersionedWorldGenerator {
        $key = $name . ':' . $version . ':' . $seed;
        $cached = $this->generators[$key] ?? null;
        if ($cached !== null) {
            return $cached;
        }
        $generator = WorldGeneratorFactory::create($name, $seed, $states);
        if (!$generator instanceof VersionedWorldGenerator || $generator->version() !== $version) {
            throw new ChunkTransferException('Chunk generation request uses an unsupported generator version.');
        }
        $this->generators[$key] = $generator;
        $this->generatorOrder[] = $key;
        if (count($this->generatorOrder) > self::MAXIMUM_CACHED_GENERATORS) {
            $expired = array_shift($this->generatorOrder);
            unset($this->generators[$expired]);
        }

        return $generator;
    }
}
