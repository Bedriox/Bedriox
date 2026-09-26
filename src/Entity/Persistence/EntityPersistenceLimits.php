<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

final class EntityPersistenceLimits
{
    public const int MAX_DOCUMENT_BYTES = 4_194_304;
    public const int MAX_RECORD_BYTES = 131_072;
    public const int MAX_RECORDS = 4_096;
    public const int MAX_CUSTOM_DATA_BYTES = 65_536;
    public const int MAX_ITEM_DATA_BYTES = 32_768;
    public const int MAX_EQUIPMENT_ENTRIES = 6;
    public const int MAX_WORLD_NAME_BYTES = 128;
    public const int MAX_IDENTIFIER_BYTES = 256;
    public const int MAX_VARIANT_BYTES = 128;

    private function __construct() {}
}
