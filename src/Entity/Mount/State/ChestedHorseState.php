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

namespace Bedriox\Server\Entity\Mount\State;

use InvalidArgumentException;

trait ChestedHorseState
{
    private bool $chested = false;

    final public function hasChest(): bool
    {
        return $this->chested;
    }

    final public function getStorageSlotCount(): int
    {
        return $this->chested ? 15 : 0;
    }

    /** @internal Authoritative interaction mutation. */
    final public function setChested(bool $chested): void
    {
        if ($this->chested !== $chested) {
            $this->chested = $chested;
            $this->markPresentationChanged();
        }
    }

    final protected function initializeChestedHorseState(bool $chested): void
    {
        $this->chested = $chested;
    }

    /** @return array{chested: bool} */
    final protected function chestedHorsePersistenceData(): array
    {
        return ['chested' => $this->chested];
    }

    /** @param array<mixed> $data */
    final protected function restoreChestedHorsePersistenceData(array $data): void
    {
        if (!isset($data['chested']) || !is_bool($data['chested'])) {
            throw new InvalidArgumentException('Persisted chested-horse state is malformed.');
        }
        $this->chested = $data['chested'];
    }
}
