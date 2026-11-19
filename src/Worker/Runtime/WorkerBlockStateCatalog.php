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

namespace Bedriox\Server\Worker\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\NetworkBlockStateRegistry;
use Bedriox\Server\Worker\Chunk\ChunkProjectionIdentity;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;

/** One lazily materialized block-state projection shared by every task handler in a compute process. */
final class WorkerBlockStateCatalog
{
    private ?BedrockDataSet $data = null;
    private ?NetworkBlockStateRegistry $networkStates = null;
    private ?BlockStateRegistry $internalStates = null;
    private ?BlockNetworkTranslator $translator = null;
    private ?string $registryHash = null;

    public function data(): BedrockDataSet
    {
        return $this->data ??= BedrockDataSet::bundled();
    }

    public function networkStates(): NetworkBlockStateRegistry
    {
        return $this->networkStates ??= $this->data()->blockStateRegistry();
    }

    public function internalStates(): BlockStateRegistry
    {
        return $this->internalStates ??= new BlockStateRegistry($this->networkStates()->states());
    }

    public function translator(): BlockNetworkTranslator
    {
        return $this->translator ??= new BlockNetworkTranslator($this->internalStates(), $this->networkStates());
    }

    public function registryHash(): string
    {
        return $this->registryHash ??= ChunkProjectionIdentity::registryHash($this->data(), $this->networkStates());
    }
}
