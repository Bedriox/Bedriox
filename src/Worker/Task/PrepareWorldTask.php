<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Task;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Worker\WorkerTaskHandler;
use Bedriox\Server\Worker\World\WorldPreparationCodec;
use Bedriox\Server\Worker\World\WorldPreparationResult;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Generator\GeneratorContext;
use Bedriox\Server\World\Generator\PluginWorldGeneratorAdapter;
use Bedriox\Server\World\WorldGeneratorFactory;
use RuntimeException;

/** Constructs a generator and calculates its default spawn outside the server tick. */
final class PrepareWorldTask implements WorkerTaskHandler
{
    private ?BlockStateRegistry $states = null;

    public function execute(string $payload): string
    {
        $codec = new WorldPreparationCodec();
        $request = $codec->decodeRequest($payload);
        $states = $this->states ??= new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $generator = $request->workerSource === null
            ? WorldGeneratorFactory::create(
                $request->generator,
                $request->seed,
                $states,
                $request->options,
                $request->dimension,
            )
            : new PluginWorldGeneratorAdapter(
                $request->generatorIdentifier,
                $request->generatorVersion,
                $request->workerSource->class,
                new GeneratorContext($request->seed, $request->dimension, $request->options, $states),
                $request->workerSource,
            );
        if (WorldGeneratorFactory::canonicalIdentifier($generator->name()) !== $request->generatorIdentifier
            || $generator->version() !== $request->generatorVersion) {
            throw new RuntimeException('Prepared generator metadata does not match its admitted definition.');
        }

        return $codec->encodeResult(new WorldPreparationResult(
            $request->generatorIdentifier,
            $request->generatorVersion,
            $generator->defaultSpawn(),
        ));
    }
}
