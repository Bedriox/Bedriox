<?php

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
use Bedriox\Api\World\Position;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldActions;
use Bedriox\Api\World\WorldCreationOptions;
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
    public function testWorldHandleCarriesOnlyStableLoadIdentity(): void
    {
        $first = new World('minigames', 7);
        $same = new World('minigames', 7);
        $reloaded = new World('minigames', 8);

        self::assertSame('minigames', $first->id());
        self::assertSame(7, $first->loadGeneration());
        self::assertTrue($first->isSameLoad($same));
        self::assertFalse($first->isSameLoad($reloaded));
        self::assertTrue($first->isSameWorld($reloaded));
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
        self::assertSame(135.0, $resolved->yaw);
        self::assertSame(-30.0, $resolved->pitch);

        $other = new World('arena', 3);
        $explicit = (new Position(1.0, 64.0, 2.0, 90.0, 20.0, $other))->resolve($current, 0.0, 0.0);
        self::assertSame($other, $explicit->world);
        self::assertSame(90.0, $explicit->yaw);
        self::assertSame(20.0, $explicit->pitch);
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
        );
        $world = new World('arena', 2, $actions);
        $position = new BlockPosition(1, 64, -3);

        self::assertSame('minecraft:stone', $world->getBlock($position)->identifier);
        $world->setBlock($position, 'minecraft:dirt');
        self::assertSame([[$world, $position, 'minecraft:dirt']], $writes);
    }

    public function testDetachedWorldRejectsAuthoritativeMutation(): void
    {
        $this->expectException(LogicException::class);
        (new World('arena', 1))->setBlock(new BlockPosition(0, 64, 0), 'minecraft:stone');
    }
}
