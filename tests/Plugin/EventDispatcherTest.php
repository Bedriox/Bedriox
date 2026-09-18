<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\EventHandler;
use Bedriox\Api\Event\EventPriority;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class EventDispatcherTest extends TestCase
{
    public function testOrdersListenersAndDefaultsAttributedHandlerToNormal(): void
    {
        [$dispatcher, $control] = $this->dispatcher();
        $seen = [];
        $dispatcher->register('Example', TestEvent::class, static function () use (&$seen): void {
            $seen[] = 'high';
        }, EventPriority::HIGH);
        $subscriber = new AttributedSubscriber(static function () use (&$seen): void {
            $seen[] = 'normal';
        });
        $dispatcher->registerSubscriber('Example', $subscriber);
        $dispatcher->register('Example', TestEvent::class, static function () use (&$seen): void {
            $seen[] = 'low';
        }, EventPriority::LOW);

        $dispatcher->dispatch(new TestEvent());

        self::assertSame(['low', 'normal', 'high'], $seen);
        self::assertSame([], $control->failures);
    }

    public function testCancellationFilteringAndMonitorReadOnlyEnforcement(): void
    {
        [$dispatcher, $control] = $this->dispatcher();
        $seen = [];
        $dispatcher->register('Example', TestCancellableEvent::class, static function (TestCancellableEvent $event): void {
            $event->cancel();
        }, EventPriority::LOW);
        $dispatcher->register('Example', TestCancellableEvent::class, static function () use (&$seen): void {
            $seen[] = 'skipped';
        });
        $dispatcher->register('Example', TestCancellableEvent::class, static function (TestCancellableEvent $event) use (&$seen): void {
            $seen[] = 'monitor';
            $event->setCancelled(false);
        }, EventPriority::MONITOR, true);

        $event = new TestCancellableEvent();
        $dispatcher->dispatch($event);

        self::assertTrue($event->isCancelled());
        self::assertSame(['monitor'], $seen);
        self::assertCount(1, $control->failures);
        self::assertFalse($control->enabled['example']);
    }

    public function testListenerFailureRestoresEventDiscardsActionsAndCleansPluginOwnership(): void
    {
        [$dispatcher, $control, $actions, $ownership] = $this->dispatcher();
        $committed = false;
        $dispatcher->register('Example', MutableTestEvent::class, static function (MutableTestEvent $event) use ($actions, &$committed): void {
            $event->change('changed');
            $actions->stage(static function () use (&$committed): void {
                $committed = true;
            });
            throw new RuntimeException('listener failed');
        });

        $event = new MutableTestEvent('original');
        $dispatcher->dispatch($event);

        self::assertSame('original', $event->value());
        self::assertFalse($committed);
        self::assertCount(1, $control->failures);
        self::assertSame('event-listener', $control->failures[0][2]?->operation);
        self::assertSame(0, $ownership->count('Example'));
        self::assertSame(0, $dispatcher->listenerCount());
    }

    public function testMonitorCannotStageServerActions(): void
    {
        [$dispatcher, $control, $actions] = $this->dispatcher();
        $committed = false;
        $dispatcher->register('Example', TestEvent::class, static function () use ($actions, &$committed): void {
            $actions->stage(static function () use (&$committed): void {
                $committed = true;
            });
        }, EventPriority::MONITOR);

        $dispatcher->dispatch(new TestEvent());

        self::assertFalse($committed);
        self::assertCount(1, $control->failures);
        self::assertFalse($control->enabled['example']);
    }

    public function testProgrammaticSubscriptionAndNestedDispatchAreBounded(): void
    {
        [$dispatcher] = $this->dispatcher(maxDepth: 2);
        $calls = 0;
        $subscription = $dispatcher->register('Example', TestEvent::class, function (TestEvent $event) use (&$calls, $dispatcher): void {
            ++$calls;
            if ($calls < 3) {
                $dispatcher->dispatch($event);
            }
        });

        $dispatcher->dispatch(new TestEvent());

        self::assertSame(2, $calls);
        self::assertFalse($subscription->isRegistered());
    }

    public function testRejectsInvalidAttributedSignature(): void
    {
        [$dispatcher] = $this->dispatcher();

        $this->expectException(PluginException::class);
        $dispatcher->registerSubscriber('Example', new InvalidSubscriber());
    }

    /** @return array{EventDispatcher, FakeRuntimeControl, PluginActionBuffer, PluginOwnershipRegistry} */
    private function dispatcher(int $maxDepth = 16): array
    {
        $control = new FakeRuntimeControl();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $ownership = new PluginOwnershipRegistry();
        $dispatcher = new EventDispatcher($control, $execution, $actions, $ownership, $maxDepth);
        $control->dispatcher = $dispatcher;
        $control->ownership = $ownership;

        return [$dispatcher, $control, $actions, $ownership];
    }
}

final class FakeRuntimeControl implements PluginRuntimeControl
{
    /** @var array<string, bool> */
    public array $enabled = ['example' => true];
    /** @var list<array{string, Throwable, ?PluginExecutionFrame}> */
    public array $failures = [];
    public ?EventDispatcher $dispatcher = null;
    public ?PluginOwnershipRegistry $ownership = null;

    public function isEnabled(string $plugin): bool
    {
        return $this->enabled[strtolower($plugin)] ?? false;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $this->failures[] = [$plugin, $failure, $frame];
        $this->enabled[strtolower($plugin)] = false;
        $this->ownership?->releaseAll($plugin);
    }
}

final class TestEvent extends Event {}

final class TestCancellableEvent extends CancellableEvent {}

final class MutableTestEvent extends Event
{
    public function __construct(private string $value) {}

    public function value(): string
    {
        return $this->value;
    }

    public function change(string $value): void
    {
        $this->assertMutable();
        $this->value = $value;
    }

    protected function state(): mixed
    {
        return $this->value;
    }

    protected function replaceState(mixed $state): void
    {
        $this->value = is_string($state) ? $state : '';
    }
}

final class AttributedSubscriber
{
    public function __construct(private readonly Closure $record) {}

    #[EventHandler]
    public function onEvent(TestEvent $event): void
    {
        ($this->record)();
    }
}

final class InvalidSubscriber
{
    #[EventHandler]
    public static function invalid(TestEvent $event): void {}
}
