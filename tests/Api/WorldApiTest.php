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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Event\World\WorldCreatedEvent;
use Bedriox\Api\Event\World\WorldCreateEvent;
use Bedriox\Api\Event\World\WorldLoadedEvent;
use Bedriox\Api\Event\World\WorldLoadEvent;
use Bedriox\Api\Event\World\WorldSavedEvent;
use Bedriox\Api\Event\World\WorldSaveEvent;
use Bedriox\Api\Event\World\WorldUnloadedEvent;
use Bedriox\Api\Event\World\WorldUnloadEvent;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\DimensionCreationOptions;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\Particle\Particle;
use Bedriox\Api\World\Particle\ParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldActions;
use Bedriox\Api\World\WorldCreationOptions;
use Bedriox\Api\World\WorldDifficulty;
use Bedriox\Api\World\WorldInfo;
use Bedriox\Api\World\WorldOperationFailure;
use Bedriox\Api\World\WorldOperationFailureCode;
use Bedriox\Api\World\WorldOperationResult;
use Bedriox\Api\World\WorldOperationState;
use Bedriox\Api\World\WorldOperationType;
use Bedriox\Api\World\WorldUnloadOptions;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorldApiTest extends TestCase
{
    public function testWorldHandleCarriesExactDimensionAndStableLoadIdentity(): void
    {
        $first = new World('minigames', 7, dimensionType: DimensionType::NETHER);
        $same = new World('minigames', 7, dimensionType: DimensionType::NETHER);
        $overworld = new World('minigames', 7);
        $reloaded = new World('minigames', 8, dimensionType: DimensionType::NETHER);

        self::assertSame('minigames', $first->id());
        self::assertSame(7, $first->loadGeneration());
        self::assertSame(DimensionType::NETHER, $first->getDimensionType());
        self::assertTrue($first->isSameLoad($same));
        self::assertFalse($first->isSameLoad($overworld));
        self::assertFalse($first->isSameLoad($reloaded));
        self::assertTrue($first->isSameWorld($reloaded));
        self::assertFalse($first->isSameWorld($overworld));
    }

    public function testWorldResolvesOnlyCanonicalDimensionsFromItsLoadedWorld(): void
    {
        $overworld = null;
        $nether = null;
        $resolver = static function (World $source, DimensionType $dimension) use (&$overworld, &$nether): ?World {
            return match ($dimension) {
                DimensionType::OVERWORLD => $overworld,
                DimensionType::NETHER => $nether,
                DimensionType::END => null,
            };
        };
        $overworld = new World('survival', 4, dimensionResolver: $resolver);
        $nether = new World(
            'survival',
            4,
            dimensionType: DimensionType::NETHER,
            dimensionResolver: $resolver,
        );

        self::assertSame($overworld, $overworld->getDimension(DimensionType::OVERWORLD));
        self::assertSame($nether, $overworld->getDimension(DimensionType::NETHER));
        self::assertSame($overworld, $nether->getDimension(DimensionType::OVERWORLD));
        self::assertNull($overworld->getDimension(DimensionType::END));
    }

    #[DataProvider('invalidWorldHandleProvider')]
    public function testWorldHandleRejectsNonCanonicalOrInvalidIdentity(string $id, int $generation): void
    {
        $this->expectException(InvalidArgumentException::class);

        new World($id, $generation);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidWorldHandleProvider(): iterable
    {
        yield 'empty ID' => ['', 1];
        yield 'uppercase ID' => ['Arena', 1];
        yield 'path traversal' => ['../arena', 1];
        yield 'zero generation' => ['arena', 0];
    }

    public function testPositionResolvesOmittedContextWithoutReplacingExplicitContext(): void
    {
        $current = new World('survival', 1);
        $destination = new Position(12.5, 70.0, -8.5);
        $resolved = $destination->resolve($current, 135.0, -30.0);

        self::assertTrue($resolved->isResolved());
        self::assertSame($current, $resolved->world);
        self::assertSame(DimensionType::OVERWORLD, $resolved->world->getDimensionType());
        self::assertSame(135.0, $resolved->yaw);
        self::assertSame(-30.0, $resolved->pitch);

        $other = new World('arena', 3);
        $explicit = (new Position(1.0, 64.0, 2.0, 90.0, 20.0, $other))->resolve($current, 0.0, 0.0);
        self::assertSame($other, $explicit->world);
        self::assertSame(90.0, $explicit->yaw);
        self::assertSame(20.0, $explicit->pitch);
    }

    public function testBlockPositionContainsCoordinatesWithoutDimensionIdentity(): void
    {
        $position = new BlockPosition(4, 70, -8);

        self::assertSame(4, $position->x);
        self::assertSame(70, $position->y);
        self::assertSame(-8, $position->z);
        $constructor = (new \ReflectionClass(BlockPosition::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertNotContains(
            'dimension',
            array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()),
        );
    }

    public function testWorldInfoDescribesOneExactDimension(): void
    {
        $nether = new World('survival', 2, dimensionType: DimensionType::NETHER);
        $spawn = new Position(0.5, 64.0, 0.5, 0.0, 0.0, $nether);
        $info = new WorldInfo(
            $nether,
            'Survival Nether',
            'default',
            42,
            WorldDifficulty::NORMAL,
            18_000,
            $spawn,
            3,
        );

        self::assertSame($nether, $info->world);
        self::assertSame($spawn, $info->spawn);
        self::assertSame(DimensionType::NETHER, $info->world->getDimensionType());
    }

    public function testWorldInfoRejectsSpawnFromAnotherDimension(): void
    {
        $nether = new World('survival', 2, dimensionType: DimensionType::NETHER);
        $overworld = new World('survival', 2);

        $this->expectException(InvalidArgumentException::class);
        new WorldInfo(
            $nether,
            'Survival Nether',
            'default',
            42,
            WorldDifficulty::NORMAL,
            18_000,
            new Position(0.5, 64.0, 0.5, 0.0, 0.0, $overworld),
            0,
        );
    }

    public function testPositionRequiresCompleteBoundedStateWhenAuthoritative(): void
    {
        $this->expectException(LogicException::class);

        (new Position(0.0, 64.0, 0.0))->validateResolved();
    }

    #[DataProvider('invalidPositionProvider')]
    public function testPositionResolutionRejectsInvalidValues(Position $position): void
    {
        $this->expectException(InvalidArgumentException::class);

        $position->resolve(new World('world', 1), 0.0, 0.0);
    }

    /** @return iterable<string, array{Position}> */
    public static function invalidPositionProvider(): iterable
    {
        yield 'non-finite coordinate' => [new Position(INF, 64.0, 0.0)];
        yield 'horizontal limit' => [new Position(30_000_001.0, 64.0, 0.0)];
        yield 'pitch' => [new Position(0.0, 64.0, 0.0, 0.0, 91.0)];
    }

    public function testCreationOptionsCarryBoundedImmutableWorldConfiguration(): void
    {
        $options = new WorldCreationOptions(
            generator: 'testfeatures:checkerboard',
            seed: 42,
            generatorOptions: ['tile_size' => 8, 'nested' => ['enabled' => true]],
            displayName: 'Checkerboard',
            initialTime: 6_000,
            spawn: new Position(0.5, 80.0, 0.5, 0.0, 0.0),
        );

        self::assertSame('testfeatures:checkerboard', $options->generator);
        self::assertSame(42, $options->seed);
        self::assertSame(8, $options->generatorOptions['tile_size']);
        self::assertSame('Checkerboard', $options->displayName);
    }

    public function testCreationOptionsResolveIndependentDimensionGenerators(): void
    {
        $netherSpawn = new Position(8.5, 72.0, -4.5, 90.0, 0.0);
        $options = new WorldCreationOptions(
            generator: 'default',
            seed: 42,
            dimensions: [
                DimensionType::NETHER->value => new DimensionCreationOptions(
                    'testfeatures:hellscape',
                    99,
                    ['lava_sea_height' => 48],
                    $netherSpawn,
                ),
            ],
        );

        $overworld = $options->dimension(DimensionType::OVERWORLD);
        $nether = $options->dimension(DimensionType::NETHER);
        self::assertSame('default', $overworld->generator);
        self::assertSame(42, $overworld->seed);
        self::assertSame('testfeatures:hellscape', $nether->generator);
        self::assertSame(99, $nether->seed);
        self::assertSame(48, $nether->generatorOptions['lava_sea_height']);
        self::assertSame($netherSpawn, $nether->spawn);
    }

    public function testCreationOptionsRejectUnknownDimensionKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorldCreationOptions(dimensions: [
            'moon' => new DimensionCreationOptions('default'),
        ]);
    }

    public function testCreationOptionsRejectUnnamespacedCustomGenerator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorldCreationOptions(generator: 'checkerboard');
    }

    public function testCreationOptionsRejectExistingWorldAsNewSpawnOwner(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorldCreationOptions(spawn: new Position(0.0, 64.0, 0.0, world: new World('world', 1)));
    }

    public function testWorldOperationResultRequiresConsistentTerminalOutcome(): void
    {
        $world = new World('arena', 4);
        $success = new WorldOperationResult(
            WorldOperationType::LOAD,
            WorldOperationState::SUCCEEDED,
            'arena',
            $world,
        );
        $failure = new WorldOperationResult(
            WorldOperationType::UNLOAD,
            WorldOperationState::FAILED,
            'arena',
            $world,
            new WorldOperationFailure(WorldOperationFailureCode::OCCUPIED, 'Players remain in the world.'),
        );

        self::assertTrue($success->succeeded());
        self::assertFalse($failure->succeeded());
        self::assertSame($world, $failure->world);
    }

    public function testWorldLifecycleEventsFollowCancellablePreAndImmutablePostConventions(): void
    {
        $world = new World('arena', 1);
        $creation = new WorldCreationOptions();
        $unload = new WorldUnloadOptions();
        $preEvents = [
            new WorldCreateEvent('arena', $creation),
            new WorldLoadEvent('arena'),
            new WorldSaveEvent($world),
            new WorldUnloadEvent($world, $unload),
        ];
        foreach ($preEvents as $event) {
            self::assertInstanceOf(CancellableEvent::class, $event);
            $event->cancel();
            self::assertTrue($event->isCancelled());
        }

        foreach ([
            new WorldCreatedEvent($world, $creation),
            new WorldLoadedEvent($world),
            new WorldSavedEvent($world),
            new WorldUnloadedEvent($world, $unload),
        ] as $event) {
            self::assertInstanceOf(PostEvent::class, $event);
        }
    }

    public function testWorldBlockMethodsDelegateWithTheOwningHandle(): void
    {
        $writes = [];
        $actions = new WorldActions(
            static fn(World $world, BlockPosition $position): Block => new Block($position, 'minecraft:stone'),
            static function (World $world, BlockPosition $position, string $identifier) use (&$writes): void {
                $writes[] = [$world, $position, $identifier];
            },
            static function (): void {},
        );
        $world = new World('arena', 2, $actions);
        $position = new BlockPosition(1, 64, -3);

        self::assertSame('minecraft:stone', $world->getBlock($position)->identifier);
        $world->setBlock($position, 'minecraft:dirt');
        self::assertSame([[$world, $position, 'minecraft:dirt']], $writes);
    }

    public function testWorldParticleMethodDelegatesTypedPresentationRequest(): void
    {
        $requests = [];
        $actions = new WorldActions(
            static fn(World $world, BlockPosition $position): Block => new Block($position, 'minecraft:air'),
            static function (): void {},
            static function (World $world, Position $position, Particle $particle, ?array $players) use (&$requests): void {
                $requests[] = [$world, $position, $particle, $players];
            },
        );
        $world = new World('world', 1, $actions);
        $position = new Position(1.5, 65.0, -2.5);
        $particle = new SimpleParticle(ParticleType::FLAME);

        $world->spawnParticle($position, $particle);

        self::assertSame([[$world, $position, $particle, null]], $requests);
    }

    public function testWorldWeatherMethodsDelegateTypedAuthoritativeState(): void
    {
        $current = new WeatherState(WeatherType::CLEAR, 600);
        $requested = [];
        $actions = new WorldActions(
            static fn(World $world, BlockPosition $position): Block => new Block($position, 'minecraft:air'),
            static function (): void {},
            static function (): void {},
            static fn(World $world): WeatherState => $current,
            static function (World $world, WeatherState $weather) use (&$requested): bool {
                $requested[] = [$world, $weather];

                return true;
            },
        );
        $world = new World('world', 1, $actions);
        $rain = new WeatherState(WeatherType::RAIN, 1_200);

        self::assertSame($current, $world->getWeather());
        self::assertTrue($world->setWeather($rain));
        self::assertSame([[$world, $rain]], $requested);
    }

    public function testWorldParticleMethodRejectsInvalidAudienceMember(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new World('world', 1))->spawnParticle(
            new Position(1.5, 65.0, -2.5),
            new SimpleParticle(ParticleType::FLAME),
            ['not-a-player'],
        );
    }

    public function testDetachedWorldRejectsAuthoritativeMutation(): void
    {
        $this->expectException(LogicException::class);
        (new World('arena', 1))->setBlock(new BlockPosition(0, 64, 0), 'minecraft:stone');
    }
}
