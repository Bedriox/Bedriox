<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use RuntimeException;

/** Bounded binary IPC representation for the exact snapshots committed by an ownership move. */
final readonly class EntityOwnershipTransferResultCodec
{
    private const string MAGIC = 'BXER';
    private const int VERSION = 1;

    public function __construct(private EntityPersistenceCodec $entities) {}

    public function encode(EntityOwnershipTransferResult $result): string
    {
        $source = $this->entities->encode($result->sourceAfter);
        $destination = $this->entities->encode($result->destinationAfter);

        return self::MAGIC
            . pack('n', self::VERSION)
            . pack('N', strlen($source)) . $source
            . pack('N', strlen($destination)) . $destination;
    }

    public function decode(string $bytes): EntityOwnershipTransferResult
    {
        $maximum = 4 + 2 + 4 + EntityPersistenceLimits::MAX_DOCUMENT_BYTES
            + 4 + EntityPersistenceLimits::MAX_DOCUMENT_BYTES;
        if (strlen($bytes) < 14 || strlen($bytes) > $maximum) {
            throw new RuntimeException('Entity ownership transfer result is truncated or oversized.');
        }
        $reader = new EntityPersistenceBinaryReader($bytes);
        if ($reader->read(4) !== self::MAGIC || $reader->unsignedShort() !== self::VERSION) {
            throw new RuntimeException('Entity ownership transfer result version is unsupported.');
        }
        $source = $this->entities->decode($reader->sizedBytes(EntityPersistenceLimits::MAX_DOCUMENT_BYTES));
        $destination = $this->entities->decode($reader->sizedBytes(EntityPersistenceLimits::MAX_DOCUMENT_BYTES));
        $reader->finish();

        return new EntityOwnershipTransferResult($source, $destination);
    }
}
