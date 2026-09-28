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

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Server\Entity\EntityUuid;
use RuntimeException;

/** Bounded binary IPC representation for an atomic entity ownership transfer. */
final readonly class EntityOwnershipTransferCodec
{
    private const string MAGIC = 'BXET';
    private const int VERSION = 1;

    public function __construct(private EntityPersistenceCodec $entities) {}

    public function encode(EntityOwnershipTransfer $transfer): string
    {
        $uuid = hex2bin(str_replace('-', '', $transfer->uuid));
        if (!is_string($uuid) || strlen($uuid) !== 16) {
            throw new RuntimeException('Entity ownership transfer UUID cannot be encoded.');
        }
        $source = $this->entities->encode($transfer->sourceAfter);
        $destination = $this->entities->encode($transfer->destinationAfter);

        return self::MAGIC
            . pack('n', self::VERSION)
            . $uuid
            . self::nonNegativeLong($transfer->expectedEntityRevision)
            . pack('N', strlen($source)) . $source
            . pack('N', strlen($destination)) . $destination;
    }

    public function decode(string $bytes): EntityOwnershipTransfer
    {
        $maximum = 4 + 2 + 16 + 8 + 4 + EntityPersistenceLimits::MAX_DOCUMENT_BYTES
            + 4 + EntityPersistenceLimits::MAX_DOCUMENT_BYTES;
        if (strlen($bytes) < 42 || strlen($bytes) > $maximum) {
            throw new RuntimeException('Entity ownership transfer payload is truncated or oversized.');
        }
        $reader = new EntityPersistenceBinaryReader($bytes);
        if ($reader->read(4) !== self::MAGIC || $reader->unsignedShort() !== self::VERSION) {
            throw new RuntimeException('Entity ownership transfer version is unsupported.');
        }
        $uuid = self::uuid($reader->read(16));
        $expectedRevision = $reader->nonNegativeLong();
        $source = $this->entities->decode($reader->sizedBytes(EntityPersistenceLimits::MAX_DOCUMENT_BYTES));
        $destination = $this->entities->decode($reader->sizedBytes(EntityPersistenceLimits::MAX_DOCUMENT_BYTES));
        $reader->finish();

        return new EntityOwnershipTransfer($uuid, $expectedRevision, $source, $destination);
    }

    private static function nonNegativeLong(int $value): string
    {
        if ($value < 0) {
            throw new RuntimeException('Entity ownership transfer revision cannot be encoded.');
        }

        return pack('J', $value);
    }

    private static function uuid(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return EntityUuid::validate(
            substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12),
        );
    }
}
