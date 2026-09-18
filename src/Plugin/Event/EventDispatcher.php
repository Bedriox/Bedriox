<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin\Event;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\EventHandler;
use Bedriox\Api\Event\EventPriority;
use Bedriox\Api\Event\ListenerSubscription;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class EventDispatcher
{
    /** @var array<int, RegisteredListener> */
    private array $listeners = [];
    /** @var array<class-string, list<array{method: string, event: class-string<Event>, priority: EventPriority, receiveCancelled: bool}>> */
    private array $subscriberCache = [];
    private int $nextId = 1;
    private int $sequence = 1;
    private int $depth = 0;

    public function __construct(
        private readonly PluginRuntimeControl $plugins,
        private readonly PluginExecutionContext $executionContext,
        private readonly PluginActionBuffer $actions,
        private readonly PluginOwnershipRegistry $ownership,
        private readonly int $maxDepth = 16,
        private readonly int $maxListenersPerDispatch = 1024,
    ) {
        if ($maxDepth < 1 || $maxDepth > 128 || $maxListenersPerDispatch < 1 || $maxListenersPerDispatch > 65536) {
            throw new PluginException('Invalid event dispatcher limits.');
        }
    }

    /**
     * @param string $eventClass
     * @param callable $listener
     */
    public function register(
        string $plugin,
        string $eventClass,
        callable $listener,
        EventPriority $priority = EventPriority::NORMAL,
        bool $receiveCancelled = false,
        ?string $description = null,
    ): ListenerSubscription {
        if (!$this->plugins->isEnabled($plugin)) {
            throw new PluginException("Disabled plugin {$plugin} cannot register event listeners.");
        }
        if (!is_a($eventClass, Event::class, true)) {
            throw new PluginException("Listener event type must extend " . Event::class . '.');
        }
        $id = $this->nextId++;
        $registered = new RegisteredListener(
            $id,
            $this->sequence++,
            $plugin,
            $eventClass,
            Closure::fromCallable($listener),
            $priority,
            $receiveCancelled,
            $description ?? 'programmatic listener',
        );
        $this->listeners[$id] = $registered;
        $this->ownership->own($plugin, "event-listener:{$id}", fn() => $this->unregister($id));

        return new EventSubscription($this, $id);
    }

    public function registerSubscriber(string $plugin, object $subscriber): void
    {
        $class = $subscriber::class;
        $definitions = $this->subscriberCache[$class] ??= $this->inspectSubscriber($class);
        foreach ($definitions as $definition) {
            $this->register(
                $plugin,
                $definition['event'],
                $subscriber->{$definition['method']}(...),
                $definition['priority'],
                $definition['receiveCancelled'],
                $class . '::' . $definition['method'],
            );
        }
    }

    public function dispatch(Event $event): Event
    {
        if ($this->depth >= $this->maxDepth) {
            throw new PluginException('Maximum event recursion depth exceeded.');
        }
        $matching = array_values(array_filter(
            $this->listeners,
            static fn(RegisteredListener $listener): bool => $event instanceof $listener->eventClass,
        ));
        usort($matching, static fn(RegisteredListener $a, RegisteredListener $b): int =>
            [$a->priority->value, $a->sequence] <=> [$b->priority->value, $b->sequence]);
        if (count($matching) > $this->maxListenersPerDispatch) {
            throw new PluginException('Maximum listeners per event exceeded.');
        }

        ++$this->depth;
        try {
            foreach ($matching as $listener) {
                if (!isset($this->listeners[$listener->id]) || !$this->plugins->isEnabled($listener->plugin)) {
                    continue;
                }
                if ($event instanceof CancellableEvent && $event->isCancelled() && !$listener->receiveCancelled) {
                    continue;
                }
                $this->invoke($listener, $event);
            }
        } finally {
            --$this->depth;
            $event->setReadOnly(false);
        }

        return $event;
    }

    public function unregister(int $id): void
    {
        unset($this->listeners[$id]);
    }

    public function has(int $id): bool
    {
        return isset($this->listeners[$id]);
    }

    public function listenerCount(): int
    {
        return count($this->listeners);
    }

    private function invoke(RegisteredListener $listener, Event $event): void
    {
        $snapshot = $event->captureState();
        $frame = new PluginExecutionFrame(
            $listener->plugin,
            $this->plugins->version($listener->plugin),
            'event-listener',
            $event::class,
            $listener->description,
            $listener->priority,
            hrtime(true),
        );
        $this->executionContext->enter($frame);
        $readOnly = $listener->priority === EventPriority::MONITOR || $event instanceof PostEvent;
        $this->actions->begin($listener->priority !== EventPriority::MONITOR);
        $event->setReadOnly($readOnly);
        try {
            ($listener->callback)($event);
            if (!$this->plugins->isEnabled($listener->plugin)) {
                $this->actions->discard();
                $event->restoreState($snapshot);

                return;
            }
            $this->actions->commit();
        } catch (Throwable $throwable) {
            if ($this->actions->isCapturing()) {
                $this->actions->discard();
            }
            $event->setReadOnly(false);
            $event->restoreState($snapshot);
            $this->plugins->disableAfterFailure($listener->plugin, $throwable, $frame);
        } finally {
            $event->setReadOnly(false);
            $this->executionContext->leave();
        }
    }

    /**
     * @param class-string $class
     * @return list<array{method: string, event: class-string<Event>, priority: EventPriority, receiveCancelled: bool}>
     */
    private function inspectSubscriber(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $definitions = [];
        $methods = $reflection->getMethods();
        usort($methods, static fn(ReflectionMethod $a, ReflectionMethod $b): int =>
            [$a->getFileName() ?: '', $a->getStartLine() ?: 0, $a->getName()]
            <=> [$b->getFileName() ?: '', $b->getStartLine() ?: 0, $b->getName()]);
        foreach ($methods as $method) {
            $attributes = $method->getAttributes(EventHandler::class);
            if ($attributes === []) {
                continue;
            }
            if (count($attributes) !== 1 || !$method->isPublic() || $method->isStatic()) {
                throw new PluginException("Invalid event handler {$class}::{$method->getName()}");
            }
            $parameters = $method->getParameters();
            $return = $method->getReturnType();
            $type = $parameters[0]->getType() ?? null;
            if (count($parameters) !== 1 || !$type instanceof ReflectionNamedType || $type->isBuiltin()
                || !is_a($type->getName(), Event::class, true)
                || !$return instanceof ReflectionNamedType || $return->getName() !== 'void') {
                throw new PluginException("Invalid event handler signature {$class}::{$method->getName()}");
            }
            /** @var class-string<Event> $eventClass */
            $eventClass = $type->getName();
            $attribute = $attributes[0]->newInstance();
            $definitions[] = [
                'method' => $method->getName(),
                'event' => $eventClass,
                'priority' => $attribute->priority,
                'receiveCancelled' => $attribute->receiveCancelled,
            ];
        }

        return $definitions;
    }
}
