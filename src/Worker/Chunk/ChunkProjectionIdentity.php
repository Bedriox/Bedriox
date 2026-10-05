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

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\NetworkBlockStateRegistry;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use Bedriox\Server\World\BiomeRuntimeIdMap;

/** Immutable wire identities shared by chunk-preparation producers and consumers. */
final class ChunkProjectionIdentity
{
    public const int SERIALIZER_VERSION = 4;
    public const int COMPRESSION_THRESHOLD = NetworkCompressionPolicy::THRESHOLD_BYTES;
    public const string COMPRESSION_PROFILE = 'negotiated-zlib:256';

    private function __construct() {}

    public static function bundledRegistryHash(): string
    {
        static $hash = null;
        if (is_string($hash)) {
            return $hash;
        }

        $hash = self::registryHash(BedrockDataSet::bundled());

        return $hash;
    }

    public static function registryHash(BedrockDataSet $data, ?NetworkBlockStateRegistry $blocks = null): string
    {
        $context = hash_init('sha256');
        foreach (($blocks ?? $data->blockStateRegistry())->states() as $runtimeId => $state) {
            $key = $state->canonicalKey();
            hash_update($context, pack('N2', $runtimeId, strlen($key)) . $key);
        }
        foreach ((new BiomeRuntimeIdMap($data->biomeRuntimeIds()))->identifiersById() as $runtimeId => $name) {
            hash_update($context, pack('N2', $runtimeId, strlen($name)) . $name);
        }
        return hash_final($context);
    }
}
