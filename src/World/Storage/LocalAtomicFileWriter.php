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

namespace Bedriox\Server\World\Storage;

use Bedriox\Server\World\Storage\Exception\WorldDataWriteException;

final class LocalAtomicFileWriter implements AtomicFileWriter
{
    public function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new WorldDataWriteException('World data directory does not exist.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new WorldDataWriteException('Unable to create a temporary world data file.');
        }
        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new WorldDataWriteException('Unable to write the temporary world data file.');
                }
                $offset += $written;
            }
            if (!@fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw new WorldDataWriteException('Unable to flush the temporary world data file.');
            }
        } catch (\Throwable $failure) {
            @fclose($handle);
            @unlink($temporary);
            throw $failure;
        }
        @fclose($handle);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new WorldDataWriteException('Unable to publish world data atomically.');
        }
    }
}
