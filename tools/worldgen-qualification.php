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

use Bedriox\Server\Tools\WorldGenerationQualification;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/WorldGenerationQualification.php';

$seeds = [0, 1, 9_123];
$radius = 1;
$centerChunkX = 0;
$centerChunkZ = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--seeds=')) {
        $rawSeeds = explode(',', substr($argument, strlen('--seeds=')));
        if (count($rawSeeds) > 16) {
            fwrite(STDERR, "Qualification accepts between 1 and 16 seeds.\n");
            exit(2);
        }
        $seeds = [];
        foreach ($rawSeeds as $rawSeed) {
            if (!preg_match('/^-?[0-9]+$/D', $rawSeed)) {
                fwrite(STDERR, "Seeds must be comma-separated integers.\n");
                exit(2);
            }
            $seeds[] = (int) $rawSeed;
        }
    } elseif (str_starts_with($argument, '--radius=')) {
        $radius = filter_var(substr($argument, strlen('--radius=')), FILTER_VALIDATE_INT);
        if (!is_int($radius) || $radius < 1 || $radius > 4) {
            fwrite(STDERR, "Qualification radius must be between 1 and 4 chunks.\n");
            exit(2);
        }
    } elseif (str_starts_with($argument, '--center-chunk-x=')) {
        $centerChunkX = filter_var(substr($argument, strlen('--center-chunk-x=')), FILTER_VALIDATE_INT);
        if (!is_int($centerChunkX) || abs($centerChunkX) > 1_000_000) {
            fwrite(STDERR, "Center chunk X must be an integer between -1000000 and 1000000.\n");
            exit(2);
        }
    } elseif (str_starts_with($argument, '--center-chunk-z=')) {
        $centerChunkZ = filter_var(substr($argument, strlen('--center-chunk-z=')), FILTER_VALIDATE_INT);
        if (!is_int($centerChunkZ) || abs($centerChunkZ) > 1_000_000) {
            fwrite(STDERR, "Center chunk Z must be an integer between -1000000 and 1000000.\n");
            exit(2);
        }
    } else {
        fwrite(
            STDERR,
            "Usage: php tools/worldgen-qualification.php [--seeds=0,1,9123] [--radius=1-4] "
            . "[--center-chunk-x=-1000000..1000000] [--center-chunk-z=-1000000..1000000]\n",
        );
        exit(2);
    }
}

$qualification = new WorldGenerationQualification();
$reports = [];
foreach ($seeds as $seed) {
    $reports[] = $qualification->run($seed, $radius, $centerChunkX, $centerChunkZ);
}

fwrite(STDOUT, json_encode([
    'schema' => 1,
    'generated_at_utc' => gmdate(DATE_ATOM),
    'reports' => $reports,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
