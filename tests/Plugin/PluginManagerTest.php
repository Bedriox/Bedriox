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

use Bedriox\Api\Command\Command;
use Bedriox\Api\Command\CommandJob;
use Bedriox\Api\Command\CommandJobSubscription;
use Bedriox\Api\Command\CommandRegistrar;
use Bedriox\Api\Command\CommandSoftEnum;
use Bedriox\Api\Command\CommandSubscription;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\EventPriority;
use Bedriox\Api\Event\EventRegistrar;
use Bedriox\Api\Event\ListenerSubscription;
use Bedriox\Api\Plugin\Plugin;
use Bedriox\Api\Plugin\PluginContext;
use Bedriox\Api\Plugin\PluginLogger;
use Bedriox\Api\Plugin\SourcePluginRegistrar;
use Bedriox\Api\Server;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginLifecycleState;
use Bedriox\Server\Plugin\PluginManager;
use Bedriox\Server\Plugin\PluginManifest;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PluginManagerTest extends TestCase
{
    public function testLifecycleReachesEnabledAndDeliberateDisableCleansDependantsFirst(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $manager = new PluginManager(new PluginExecutionContext(), $ownership);
        $core = new LifecyclePlugin($this->context('CorePlugin'));
        $feature = new DependentLifecyclePlugin($this->context('FeaturePlugin'));
        $manager->add($this->manifest('CorePlugin', LifecyclePlugin::class), $core);
        $manager->add($this->manifest('FeaturePlugin', DependentLifecyclePlugin::class, ['CorePlugin']), $feature);

        $manager->loadAll();
        $manager->enableAll();

        self::assertSame(PluginLifecycleState::ENABLED, $manager->state('CorePlugin'));
        self::assertSame(PluginLifecycleState::ENABLED, $manager->state('FeaturePlugin'));
        self::assertSame(['load', 'enable'], $core->calls);

        $cleanup = [];
        $ownership->own('CorePlugin', 'core-resource', static function () use (&$cleanup): void {
            $cleanup[] = 'core';
        });
        $ownership->own('FeaturePlugin', 'feature-resource', static function () use (&$cleanup): void {
            $cleanup[] = 'feature';
        });
        $manager->disable('CorePlugin');

        self::assertSame(['feature', 'core'], $cleanup);
        self::assertSame(PluginLifecycleState::DISABLED, $manager->state('FeaturePlugin'));
        self::assertSame(PluginLifecycleState::DISABLED, $manager->state('CorePlugin'));
    }

    public function testLoadFailureIsContainedAndPreventsRequiredDependant(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $manager = new PluginManager(new PluginExecutionContext(), $ownership);
        $failing = new FailingLoadPlugin($this->context('FailingPlugin'));
        $dependent = new DependentLifecyclePlugin($this->context('FeaturePlugin'));
        $manager->add($this->manifest('FailingPlugin', FailingLoadPlugin::class), $failing);
        $manager->add($this->manifest('FeaturePlugin', DependentLifecyclePlugin::class, ['FailingPlugin']), $dependent);

        $manager->loadAll();
        $manager->enableAll();

        self::assertSame(PluginLifecycleState::FAILED, $manager->state('FailingPlugin'));
        self::assertSame(PluginLifecycleState::FAILED, $manager->state('FeaturePlugin'));
        self::assertCount(1, $manager->failures());
        self::assertSame('load', $manager->failures()[0]->operation);
        self::assertSame([], $dependent->calls);
        self::assertSame([], (new PluginExecutionContext())->snapshot());
    }

    private function context(string $name): PluginContext
    {
        return new PluginContext(
            $name,
            new NullPluginLogger(),
            new NullEventRegistrar(),
            new NullCommandRegistrar(),
            new NullSourcePluginRegistrar(),
            $this->createStub(Server::class),
            new NullPluginData(sys_get_temp_dir()),
        );
    }

    /**
     * @param class-string<Plugin> $main
     * @param list<string> $dependencies
     */
    private function manifest(string $name, string $main, array $dependencies = []): PluginManifest
    {
        return new PluginManifest(
            1,
            $name,
            '1.0.0',
            '^0.4',
            $main,
            __NAMESPACE__,
            [],
            $dependencies,
            [],
            'WORLD_READY',
        );
    }
}

class LifecyclePlugin extends Plugin
{
    /** @var list<string> */
    public array $calls = [];

    public function onLoad(): void
    {
        $this->calls[] = 'load';
    }

    public function onEnable(): void
    {
        $this->calls[] = 'enable';
    }

    public function onDisable(): void
    {
        $this->calls[] = 'disable';
    }
}

final class DependentLifecyclePlugin extends LifecyclePlugin {}

final class FailingLoadPlugin extends Plugin
{
    public function onLoad(): void
    {
        throw new RuntimeException('load failed');
    }
}

final class NullPluginLogger implements PluginLogger
{
    public function debug(string $message): void {}

    public function info(string $message): void {}

    public function notice(string $message): void {}

    public function warning(string $message): void {}

    public function error(string $message): void {}

    public function critical(string $message): void {}
}

final class NullEventRegistrar implements EventRegistrar
{
    public function listen(
        string $eventClass,
        callable $listener,
        EventPriority $priority = EventPriority::NORMAL,
        bool $receiveCancelled = false,
    ): ListenerSubscription {
        throw new RuntimeException('Not used by this fixture.');
    }

    public function registerSubscriber(object $subscriber): void
    {
        throw new RuntimeException('Not used by this fixture.');
    }
}

final class NullCommandRegistrar implements CommandRegistrar
{
    public function register(Command $command): CommandSubscription
    {
        throw new RuntimeException('Not used by this fixture.');
    }

    public function registerSoftEnum(string $name, array $values = []): CommandSoftEnum
    {
        throw new RuntimeException('Not used by this fixture.');
    }

    public function submitJob(CommandJob $job): CommandJobSubscription
    {
        throw new RuntimeException('Not used by this fixture.');
    }
}

final class NullSourcePluginRegistrar implements SourcePluginRegistrar
{
    public function pluginsDirectory(): string
    {
        return sys_get_temp_dir();
    }

    public function register(array $definitions): void
    {
        throw new RuntimeException('Not used by this fixture.');
    }
}
