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

namespace Bedriox\Server\Entity\Concern;

use Bedriox\Server\Entity\EntityUuid;
use InvalidArgumentException;

/** @internal Shared bounded anger state for entities that explicitly support it. */
trait AngerStateTrait
{
    public const int MAXIMUM_ANGER_TICKS = 24_000;

    private ?string $angerTargetUniqueId = null;
    private int $angerTicks = 0;

    final protected function initializeAngerState(?string $targetUniqueId = null, int $ticks = 0): void
    {
        self::validateAngerState($targetUniqueId, $ticks);
        $this->angerTargetUniqueId = $targetUniqueId;
        $this->angerTicks = $ticks;
    }

    final public function getAngerTargetUniqueId(): ?string
    {
        return $this->angerTargetUniqueId;
    }

    final public function getRemainingAngerTicks(): int
    {
        return $this->angerTicks;
    }

    final public function setAngerTargetUniqueId(?string $targetUniqueId, int $ticks): void
    {
        if ($targetUniqueId !== null) {
            $targetUniqueId = EntityUuid::validate($targetUniqueId);
        }
        self::validateAngerState($targetUniqueId, $ticks);
        if ($this->angerTargetUniqueId !== $targetUniqueId || $this->angerTicks !== $ticks) {
            $this->angerTargetUniqueId = $targetUniqueId;
            $this->angerTicks = $ticks;
            $this->markPresentationChanged();
        }
    }

    final public function advanceAngerState(int $ticks = 1): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Anger-state advance is outside its supported bound.');
        }
        if ($this->angerTicks === 0) {
            return;
        }
        $this->angerTicks = max(0, $this->angerTicks - $ticks);
        if ($this->angerTicks === 0) {
            $this->angerTargetUniqueId = null;
            $this->markPresentationChanged();
        } else {
            $this->markChanged();
        }
    }

    /** @return array{angerTargetUniqueId: ?string, angerTicks: int} */
    final protected function angerPersistenceData(): array
    {
        return ['angerTargetUniqueId' => $this->angerTargetUniqueId, 'angerTicks' => $this->angerTicks];
    }

    /** @param array<mixed> $data */
    final protected function restoreAngerPersistenceData(array $data): void
    {
        if (!array_key_exists('angerTargetUniqueId', $data) || !array_key_exists('angerTicks', $data)
            || ($data['angerTargetUniqueId'] !== null && !is_string($data['angerTargetUniqueId']))
            || !is_int($data['angerTicks'])) {
            throw new InvalidArgumentException('Persisted anger state is malformed.');
        }
        self::validateAngerState($data['angerTargetUniqueId'], $data['angerTicks']);
        $this->angerTargetUniqueId = $data['angerTargetUniqueId'];
        $this->angerTicks = $data['angerTicks'];
    }

    private static function validateAngerState(?string $targetUniqueId, int $ticks): void
    {
        if ($targetUniqueId !== null) {
            EntityUuid::validate($targetUniqueId);
        }
        if ($ticks < 0 || $ticks > self::MAXIMUM_ANGER_TICKS
            || (($targetUniqueId === null) !== ($ticks === 0))) {
            throw new InvalidArgumentException('Anger target and duration are inconsistent or outside bounds.');
        }
    }
}
