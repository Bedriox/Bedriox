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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Command\CommandRegistrar;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\CustomEntityState;
use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\CustomMobBehavior;
use Bedriox\Api\Entity\CustomMobDefinition;
use Bedriox\Api\Entity\CustomMobDespawnContext;
use Bedriox\Api\Entity\CustomMobSpawnContext;
use Bedriox\Api\Entity\CustomMobStateCodec;
use Bedriox\Api\Entity\CustomMobTickContext;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\Mob;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\EventRegistrar;
use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Plugin\PluginLogger;
use Bedriox\Api\Plugin\SourcePluginRegistrar;
use Bedriox\Api\Server;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\PluginMobEntity;
use Bedriox\Server\Plugin\OwnedEntityRegistrar;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginEntityDefinitionBridge;
use Bedriox\Server\Plugin\PluginEntityLifecycleBridge;
use Bedriox\Server\Plugin\PluginEntityRegistrar;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PluginEntityRegistrarTest extends TestCase
{
    public function testOwnerMayRegisterAndReplaceItsDefinition(): void
    {
        [$registrar, $ownership] = self::registrar('Example');
        $owned = new OwnedEntityRegistrar('Example', $registrar);
        $owned->register(self::definition('example:guardian', 20.0));
        $owned->register(self::definition('example:guardian', 40.0), true);

        $registered = $registrar->definition('example:guardian');
        self::assertNotNull($registered);
        self::assertSame('Example', $registered->owner);
        self::assertSame(40.0, $registered->definition->maximumHealth);
        self::assertSame(VanillaEntityType::ZOMBIE, $registered->definition->networkAppearance);
        self::assertSame(1, $ownership->count('Example'));
        self::assertCount(1, $registrar->all());
    }

    public function testAnotherOwnerCannotReplaceAClaimedIdentifier(): void
    {
        [$registrar] = self::registrar('Example', 'Other');
        (new OwnedEntityRegistrar('Example', $registrar))->register(self::definition('example:guardian'));

        $this->expectException(PluginException::class);
        (new OwnedEntityRegistrar('Other', $registrar))->register(self::definition('example:guardian'), true);
    }

    public function testOwnerMaySpawnItsRegisteredTypeThroughTheAuthoritativeCallback(): void
    {
        [$registrar] = self::registrar('Example', 'Other');
        $spawned = [];
        $owned = new OwnedEntityRegistrar(
            'Example',
            $registrar,
            spawner: static function (
                CustomEntityType $type,
                \Bedriox\Api\World\Position $position,
                float $yaw,
                float $pitch,
            ) use (&$spawned): bool {
                $spawned[] = [$type->identifier(), $position, $yaw, $pitch];

                return true;
            },
        );
        $owned->register(self::definition('example:guardian'));
        $owned->spawn(
            new CustomEntityType('example:guardian'),
            new \Bedriox\Api\World\Position(1.5, 64.0, -2.5),
            90.0,
            -10.0,
        );

        self::assertCount(1, $spawned);
        self::assertSame('example:guardian', $spawned[0][0]);
        self::assertSame(90.0, $spawned[0][2]);
        self::assertSame(-10.0, $spawned[0][3]);

        $this->expectException(PluginException::class);
        (new OwnedEntityRegistrar(
            'Other',
            $registrar,
            spawner: static fn(
                CustomEntityType $_type,
                \Bedriox\Api\World\Position $_position,
                float $_yaw,
                float $_pitch,
            ): bool => true,
        ))->spawn(
            new CustomEntityType('example:guardian'),
            new \Bedriox\Api\World\Position(0.0, 64.0, 0.0),
        );
    }

    public function testLifecycleCleanupRemovesEveryOwnedDefinition(): void
    {
        [$registrar, $ownership, $plugins] = self::registrar('Example');
        $owned = new OwnedEntityRegistrar('Example', $registrar);
        $owned->register(self::definition('example:first'));
        $owned->register(self::definition('example:second'));

        self::assertSame([], $ownership->releaseAll('eXaMpLe'));
        self::assertFalse($registrar->has('example:first'));
        self::assertFalse($registrar->has('example:second'));
        self::assertSame(0, $ownership->count('Example'));

        $plugins->enable('Example');
        $owned->register(self::definition('example:third'));
        self::assertTrue($registrar->has('example:third'));
        self::assertSame(1, $ownership->count('Example'));
    }

    public function testDisabledOwnerAndPerPluginCapacityAreRejected(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $plugins = new EntityTestPluginRuntimeControl($ownership);
        $plugins->enable('Example');
        $registrar = new PluginEntityRegistrar(
            $plugins,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            $ownership,
            maximumDefinitions: 2,
            maximumDefinitionsPerPlugin: 1,
        );
        $owned = new OwnedEntityRegistrar('Example', $registrar);
        $owned->register(self::definition('example:first'));

        try {
            $owned->register(self::definition('example:second'));
            self::fail('The per-plugin entity-definition limit was not enforced.');
        } catch (PluginException) {
            self::assertFalse($registrar->has('example:second'));
        }

        $plugins->disable('Example');
        $this->expectException(PluginException::class);
        $owned->register(self::definition('example:third'));
    }

    public function testDefinitionAndStateBoundsAreValidated(): void
    {
        try {
            new CustomMobDefinition(
                new CustomEntityType('example:invalid'),
                VanillaEntityType::COW,
                EntityCategory::ANIMAL,
                0.0,
                1.0,
                10.0,
                static fn(): CustomMobBehavior => new EntityTestMobBehavior(),
            );
            self::fail('A zero-width custom mob was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Custom mob dimensions are outside their supported bounds.', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        new CustomEntityState(1, str_repeat('x', CustomEntityState::MAXIMUM_BYTES + 1));
    }

    public function testFactoryAndPersistenceCodecRunInsideAttributedBoundary(): void
    {
        [$registrar, , , $execution] = self::registrar('Example');
        $definition = self::definition(
            'example:stateful',
            stateCodec: new EntityTestStateCodec(),
            maximumStateBytes: 16,
        );
        (new OwnedEntityRegistrar('Example', $registrar))->register($definition);

        $behavior = $registrar->createBehavior($definition->type);
        self::assertInstanceOf(EntityTestMobBehavior::class, $behavior);
        $behavior->state = 'saved';
        $state = $registrar->encodeState($definition->type, $behavior);
        self::assertNotNull($state);
        self::assertSame('saved', $state->bytes());
        $behavior->state = '';
        self::assertTrue($registrar->restoreState($definition->type, $behavior, $state));
        self::assertSame('saved', $behavior->state);
        self::assertSame([], $execution->snapshot());
    }

    public function testOversizedCodecOutputDisablesOwnerAndCleansDefinitions(): void
    {
        [$registrar, $ownership, $plugins, $execution] = self::registrar('Example');
        $definition = self::definition(
            'example:oversized',
            stateCodec: new EntityTestStateCodec('oversized'),
            maximumStateBytes: 4,
        );
        (new OwnedEntityRegistrar('Example', $registrar))->register($definition);
        $behavior = $registrar->createBehavior($definition->type);
        self::assertNotNull($behavior);

        self::assertNull($registrar->encodeState($definition->type, $behavior));
        self::assertFalse($plugins->isEnabled('Example'));
        self::assertFalse($registrar->has($definition->type));
        self::assertSame(0, $ownership->count('Example'));
        self::assertSame('entity-state-encode', $plugins->failures[0]->operation ?? null);
        self::assertSame([], $execution->snapshot());
    }

    public function testLifecycleHookFailureIsContainedToItsOwner(): void
    {
        [$registrar, , $plugins, $execution] = self::registrar('Example');
        $definition = self::definition(
            'example:failing',
            factory: static fn(): CustomMobBehavior => new EntityFailingTickBehavior(),
        );
        (new OwnedEntityRegistrar('Example', $registrar))->register($definition);
        $behavior = $registrar->createBehavior($definition->type);
        self::assertNotNull($behavior);

        self::assertFalse($registrar->invokeTick(
            $definition->type,
            $behavior,
            new CustomMobTickContext(
                $this->createStub(Mob::class),
                20,
                $this->createStub(MobController::class),
            ),
        ));
        self::assertFalse($plugins->isEnabled('Example'));
        self::assertSame('entity-tick-hook', $plugins->failures[0]->operation ?? null);
        self::assertSame([], $execution->snapshot());
    }

    public function testPluginContextUsesUnavailableRegistrarByDefault(): void
    {
        $context = new PluginContext(
            'Example',
            $this->createStub(PluginLogger::class),
            $this->createStub(EventRegistrar::class),
            $this->createStub(CommandRegistrar::class),
            $this->createStub(SourcePluginRegistrar::class),
            $this->createStub(Server::class),
            new NullPluginData(sys_get_temp_dir()),
        );

        $this->expectException(LogicException::class);
        $context->entities()->register(self::definition('example:unavailable'));
    }

    public function testBoundDefinitionsCreateAuthoritativePluginMobsAndCleanUpWithOwner(): void
    {
        [$registrar, $ownership] = self::registrar('Example');
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        $owned = new OwnedEntityRegistrar('Example', $registrar);
        $owned->register(self::definition(
            'example:guardian',
            factory: static fn(): CustomMobBehavior => new EntityLifecycleMobBehavior(),
        ));

        $registration = $definitions->require('example:guardian');
        $entity = ($registration->factory)(
            '00000000-0000-4000-8000-000000000101',
            101,
            'world',
            new Position(1.5, 64.0, 2.5),
            90.0,
            10.0,
        );

        self::assertInstanceOf(PluginMobEntity::class, $entity);
        self::assertSame('Example', $entity->pluginOwner());
        self::assertSame('minecraft:zombie', $entity->definition()->networkIdentifier);
        self::assertSame('Example', $registration->owner);

        $lifecycle = new PluginEntityLifecycleBridge($registrar);
        self::assertTrue($lifecycle->spawned($entity, SpawnCause::PLUGIN));
        self::assertTrue($lifecycle->tick($entity, 20));
        self::assertTrue($lifecycle->aiTick($entity, 20));
        self::assertTrue($lifecycle->despawned($entity));
        $behavior = $entity->customBehavior();
        self::assertInstanceOf(EntityLifecycleMobBehavior::class, $behavior);
        self::assertSame(1, $behavior->spawned);
        self::assertSame(1, $behavior->ticked);
        self::assertSame(1, $behavior->aiTicked);
        self::assertSame(1, $behavior->despawned);

        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertNull($definitions->get('example:guardian'));
        self::assertFalse($lifecycle->isAvailable($entity));
    }

    public function testBoundDefinitionReplacementIsOwnerScopedAndAtomic(): void
    {
        [$registrar] = self::registrar('Example', 'Other');
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        (new OwnedEntityRegistrar('Example', $registrar))->register(self::definition('example:guardian', 20.0));
        (new OwnedEntityRegistrar('Example', $registrar))->register(self::definition('example:guardian', 40.0), true);

        self::assertSame(40.0, $definitions->require('example:guardian')->definition->maximumHealth);
        try {
            (new OwnedEntityRegistrar('Other', $registrar))->register(self::definition('example:guardian'), true);
            self::fail('A second plugin replaced the authoritative custom mob definition.');
        } catch (PluginException) {
            self::assertSame(40.0, $definitions->require('example:guardian')->definition->maximumHealth);
            self::assertSame('Example', $definitions->require('example:guardian')->owner);
        }
    }

    public function testFailedLifecycleCallbackDiscardsBufferedControlIntents(): void
    {
        [$registrar, , $plugins] = self::registrar('Example');
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        (new OwnedEntityRegistrar('Example', $registrar))->register(self::definition(
            'example:failing-control',
            factory: static fn(): CustomMobBehavior => new EntityFailingControlBehavior(),
        ));
        $entity = ($definitions->require('example:failing-control')->factory)(
            '00000000-0000-4000-8000-000000000102',
            102,
            'world',
            new Position(1.5, 64.0, 2.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(PluginMobEntity::class, $entity);

        self::assertFalse((new PluginEntityLifecycleBridge($registrar))->tick($entity, 20));
        self::assertSame(0.0, $entity->getMotion()->x);
        self::assertSame(0.0, $entity->getMotion()->y);
        self::assertSame(0.0, $entity->getMotion()->z);
        self::assertFalse($entity->pluginDespawnRequested());
        self::assertFalse($plugins->isEnabled('Example'));
        self::assertFalse($registrar->actions()->isCapturing());
    }

    public function testSuccessfulLifecycleCallbackCommitsBoundedControlIntents(): void
    {
        [$registrar] = self::registrar('Example');
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        (new OwnedEntityRegistrar('Example', $registrar))->register(self::definition(
            'example:controlled',
            factory: static fn(): CustomMobBehavior => new EntityControlBehavior(),
        ));
        $entity = ($definitions->require('example:controlled')->factory)(
            '00000000-0000-4000-8000-000000000103',
            103,
            'world',
            new Position(1.5, 64.0, 2.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(PluginMobEntity::class, $entity);

        self::assertTrue((new PluginEntityLifecycleBridge($registrar))->tick($entity, 20));
        self::assertSame(0.25, $entity->getMotion()->x);
        self::assertSame(0.5, $entity->getMotion()->y);
        self::assertSame(-0.75, $entity->getMotion()->z);
        self::assertSame(270.0, $entity->getYaw());
        self::assertTrue($entity->pluginDespawnRequested());
    }

    public function testLiveEntityKeepsItsImmutableDefinitionBindingAfterReplacement(): void
    {
        [$registrar] = self::registrar('Example');
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        $owned = new OwnedEntityRegistrar('Example', $registrar);
        $owned->register(self::definition(
            'example:versioned',
            stateCodec: new EntityTestStateCodec('old-codec'),
        ));
        $oldEntity = ($definitions->require('example:versioned')->factory)(
            '00000000-0000-4000-8000-000000000104',
            104,
            'world',
            new Position(1.5, 64.0, 2.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(PluginMobEntity::class, $oldEntity);
        $oldGeneration = $oldEntity->pluginRegistration()->generation;

        $owned->register(self::definition(
            'example:versioned',
            stateCodec: new EntityTestStateCodec('new-codec'),
        ), true);
        $newEntity = ($definitions->require('example:versioned')->factory)(
            '00000000-0000-4000-8000-000000000105',
            105,
            'world',
            new Position(2.5, 64.0, 2.5),
            0.0,
            0.0,
        );
        self::assertInstanceOf(PluginMobEntity::class, $newEntity);

        $lifecycle = new PluginEntityLifecycleBridge($registrar);
        self::assertTrue($lifecycle->isAvailable($oldEntity));
        self::assertGreaterThan($oldGeneration, $newEntity->pluginRegistration()->generation);
        self::assertSame('old-codec', $lifecycle->encodeState($oldEntity)?->bytes());
        self::assertSame('new-codec', $lifecycle->encodeState($newEntity)?->bytes());
    }

    public function testNetworkAppearanceAcceptsOnlyAdmittedVanillaIdentities(): void
    {
        [$registrar] = self::registrar('Example');
        $definitions = EntityDefinitionRegistry::baseline();
        $registrar->bindDefinitionBridge(new PluginEntityDefinitionBridge($definitions, $registrar));
        $owned = new OwnedEntityRegistrar('Example', $registrar);
        $owned->register(self::definition(
            'example:cow-appearance',
            networkAppearance: new VanillaEntityIdentifier('minecraft:cow'),
        ));
        self::assertSame(
            'minecraft:cow',
            $definitions->require('example:cow-appearance')->definition->networkIdentifier,
        );

        $this->expectException(\UnexpectedValueException::class);
        $owned->register(self::definition(
            'example:unadmitted-appearance',
            networkAppearance: new VanillaEntityIdentifier('minecraft:not_admitted'),
        ));
    }

    /**
     * @return array{PluginEntityRegistrar, PluginOwnershipRegistry, EntityTestPluginRuntimeControl, PluginExecutionContext}
     */
    private static function registrar(string ...$enabled): array
    {
        $ownership = new PluginOwnershipRegistry();
        $plugins = new EntityTestPluginRuntimeControl($ownership);
        foreach ($enabled as $plugin) {
            $plugins->enable($plugin);
        }
        $execution = new PluginExecutionContext();

        return [
            new PluginEntityRegistrar($plugins, $execution, new PluginActionBuffer(), $ownership),
            $ownership,
            $plugins,
            $execution,
        ];
    }

    /** @param null|callable(): CustomMobBehavior $factory */
    private static function definition(
        string $identifier,
        float $maximumHealth = 20.0,
        ?CustomMobStateCodec $stateCodec = null,
        int $maximumStateBytes = CustomMobDefinition::DEFAULT_MAXIMUM_STATE_BYTES,
        ?callable $factory = null,
        \Bedriox\Api\Entity\VanillaEntityIdentity $networkAppearance = VanillaEntityType::ZOMBIE,
    ): CustomMobDefinition {
        return new CustomMobDefinition(
            new CustomEntityType($identifier),
            $networkAppearance,
            EntityCategory::MONSTER,
            0.6,
            1.95,
            $maximumHealth,
            $factory ?? static fn(): CustomMobBehavior => new EntityTestMobBehavior(),
            $stateCodec ?? new EntityTestStateCodec(),
            $maximumStateBytes,
        );
    }
}

final class EntityTestMobBehavior extends CustomMobBehavior
{
    public string $state = '';
}

final class EntityFailingTickBehavior extends CustomMobBehavior
{
    public function onTick(CustomMobTickContext $context): void
    {
        throw new RuntimeException('tick failed');
    }
}

final class EntityFailingControlBehavior extends CustomMobBehavior
{
    public function onTick(CustomMobTickContext $context): void
    {
        $context->controller->setVelocity(1.0, 2.0, 3.0);
        $context->controller->despawn();

        throw new RuntimeException('control failed');
    }
}

final class EntityControlBehavior extends CustomMobBehavior
{
    public function onTick(CustomMobTickContext $context): void
    {
        $context->controller->moveToward(new \Bedriox\Api\World\Position(4.5, 64.0, 2.5), 0.4);
        $context->controller->lookAt(new \Bedriox\Api\World\Position(4.5, 64.0, 2.5));
        $context->controller->setVelocity(0.25, 0.5, -0.75);
        $context->controller->despawn();
    }
}

final class EntityLifecycleMobBehavior extends CustomMobBehavior
{
    public int $spawned = 0;
    public int $ticked = 0;
    public int $aiTicked = 0;
    public int $despawned = 0;

    public function onSpawn(CustomMobSpawnContext $context): void
    {
        ++$this->spawned;
    }

    public function onTick(CustomMobTickContext $context): void
    {
        ++$this->ticked;
    }

    public function onAiTick(CustomMobTickContext $context): void
    {
        ++$this->aiTicked;
    }

    public function onDespawn(CustomMobDespawnContext $context): void
    {
        ++$this->despawned;
    }
}

final readonly class EntityTestStateCodec implements CustomMobStateCodec
{
    public function __construct(private ?string $encoded = null) {}

    public function encode(CustomMobBehavior $behavior): CustomEntityState
    {
        if (!$behavior instanceof EntityTestMobBehavior) {
            throw new InvalidArgumentException('Unexpected behavior type.');
        }

        return new CustomEntityState(1, $this->encoded ?? $behavior->state);
    }

    public function restore(CustomMobBehavior $behavior, CustomEntityState $state): void
    {
        if (!$behavior instanceof EntityTestMobBehavior || $state->schemaVersion !== 1) {
            throw new InvalidArgumentException('Unexpected persistent state.');
        }
        $behavior->state = $state->bytes();
    }
}

final class EntityTestPluginRuntimeControl implements PluginRuntimeControl
{
    /** @var array<string, true> */
    private array $enabled = [];

    /** @var list<PluginExecutionFrame> */
    public array $failures = [];

    public function __construct(private readonly PluginOwnershipRegistry $ownership) {}

    public function enable(string $plugin): void
    {
        $this->enabled[strtolower($plugin)] = true;
    }

    public function disable(string $plugin): void
    {
        unset($this->enabled[strtolower($plugin)]);
        $this->ownership->releaseAll($plugin);
    }

    public function isEnabled(string $plugin): bool
    {
        return isset($this->enabled[strtolower($plugin)]);
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        if ($frame !== null) {
            $this->failures[] = $frame;
        }
        $this->disable($plugin);
    }
}
