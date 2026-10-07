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

namespace Bedriox\Server\Entity\Mount;

use InvalidArgumentException;

/** Protocol-independent declaration of one animal equipment slot. */
final readonly class AnimalEquipmentSlotDeclaration
{
    /** @var list<string> */
    public array $acceptedItemIdentifiers;

    /** @param array<array-key, mixed> $acceptedItemIdentifiers */
    public function __construct(
        public int $slotNumber,
        public AnimalEquipmentSlotType $type,
        array $acceptedItemIdentifiers,
        public ?string $equippedItemIdentifier = null,
    ) {
        if ($slotNumber < 0 || $slotNumber > 7 || $acceptedItemIdentifiers === []
            || count($acceptedItemIdentifiers) > 128) {
            throw new InvalidArgumentException('Animal equipment slot declaration is invalid.');
        }
        $seen = [];
        $normalized = [];
        foreach ($acceptedItemIdentifiers as $index => $identifier) {
            if ($index !== count($normalized) || !is_string($identifier) || !str_starts_with($identifier, 'minecraft:')
                || isset($seen[$identifier])) {
                throw new InvalidArgumentException('Animal equipment slot item identifier is invalid.');
            }
            $seen[$identifier] = true;
            $normalized[] = $identifier;
        }
        if ($equippedItemIdentifier !== null && !isset($seen[$equippedItemIdentifier])) {
            throw new InvalidArgumentException('Equipped animal item is not accepted by its declared slot.');
        }
        $this->acceptedItemIdentifiers = $normalized;
    }
}
