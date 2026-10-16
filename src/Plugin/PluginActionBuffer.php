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

namespace Bedriox\Server\Plugin;

use LogicException;
use Throwable;

final class PluginActionBuffer
{
    /** @var list<array{id: int, actions: list<callable(): void>, allow: bool}> */
    private array $transactions = [];

    private int $nextTransactionId = 1;

    public function isCapturing(): bool
    {
        return $this->transactions !== [];
    }

    public function begin(bool $allowActions = true): void
    {
        if ($this->nextTransactionId === PHP_INT_MAX) {
            throw new LogicException('Plugin action transaction identifier capacity is exhausted.');
        }
        $this->transactions[] = [
            'id' => $this->nextTransactionId++,
            'actions' => [],
            'allow' => $allowActions,
        ];
    }

    /** @internal Stable identifier for the currently captured plugin callback. */
    public function currentTransactionId(): ?int
    {
        $index = array_key_last($this->transactions);

        return $index === null ? null : $this->transactions[$index]['id'];
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

    /**
     * Nested commits are savepoints: their work is adopted by the parent and is
     * executed only when the outermost callback succeeds. A failure while an
     * outermost action itself is executing remains discardable, but arbitrary
     * PHP mutations completed by an earlier action cannot be generically undone.
     */
    public function commit(): void
    {
        $index = array_key_last($this->transactions);
        if ($index === null) {
            throw new LogicException('No plugin action transaction is active.');
        }

        $transaction = array_pop($this->transactions);
        $parentIndex = array_key_last($this->transactions);
        if ($parentIndex !== null) {
            array_push($this->transactions[$parentIndex]['actions'], ...$transaction['actions']);

            return;
        }

        try {
            foreach ($transaction['actions'] as $action) {
                $action();
            }
        } catch (Throwable $throwable) {
            $this->transactions[] = $transaction;
            throw $throwable;
        }
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
