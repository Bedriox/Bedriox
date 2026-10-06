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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore;
use InvalidArgumentException;
use JsonException;

/** Atomic provider-backed checkpoint owner for one world's End encounter. */
final readonly class EndEncounterStateRepository
{
    private const string NAMESPACE = 'end_encounter';
    private const int MAXIMUM_BYTES = 32_768;

    public function __construct(private TransientEntityPersistenceStore $store) {}

    public function load(): EndEncounterState
    {
        $payload = $this->store->loadTransientEntities(self::NAMESPACE);
        if ($payload === null) {
            return new EndEncounterState();
        }
        if (strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Persisted End encounter state exceeds its size limit.');
        }
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted End encounter state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('Persisted End encounter state must be an object.');
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Persisted End encounter state has an invalid field name.');
            }
            $object[$key] = $value;
        }

        return EndEncounterState::fromArray($object);
    }

    public function save(EndEncounterState $state): void
    {
        try {
            $payload = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('End encounter state could not be encoded.', previous: $error);
        }
        if (strlen($payload) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('End encounter state exceeds its size limit.');
        }
        $this->store->saveTransientEntities(self::NAMESPACE, $payload);
    }
}
