<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use LogicException;
use Throwable;

final class PluginActionBuffer
{
    /** @var list<array{actions: list<callable(): void>, allow: bool}> */
    private array $transactions = [];

    public function isCapturing(): bool
    {
        return $this->transactions !== [];
    }

    public function begin(bool $allowActions = true): void
    {
        $this->transactions[] = ['actions' => [], 'allow' => $allowActions];
    }

    public function stage(callable $action): void
    {
        if ($this->transactions === []) {
            throw new LogicException('No plugin action transaction is active.');
        }
        $index = array_key_last($this->transactions);
        if (!$this->transactions[$index]['allow']) {
            throw new LogicException('MONITOR listeners may not stage server actions.');
        }
        $this->transactions[$index]['actions'][] = $action;
    }

    public function commit(): void
    {
        $index = array_key_last($this->transactions);
        if ($index === null) {
            throw new LogicException('No plugin action transaction is active.');
        }
        foreach ($this->transactions[$index]['actions'] as $action) {
            $action();
        }
        array_pop($this->transactions);
    }

    public function discard(): void
    {
        if (array_pop($this->transactions) === null) {
            throw new LogicException('No plugin action transaction is active.');
        }
    }

    public function transact(callable $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback();
            $this->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->discard();
            throw $throwable;
        }
    }
}
