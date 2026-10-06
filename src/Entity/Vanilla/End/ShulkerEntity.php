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

namespace Bedriox\Server\Entity\Vanilla\End;

use Bedriox\Api\Entity\Vanilla\Shulker;
use Bedriox\Api\World\BlockFace;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class ShulkerEntity extends MonsterEntity implements Shulker, IntrinsicEntityPersistence
{
    private BlockFace $attachmentFace = BlockFace::DOWN;

    private int $peekAmount = 0;

    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::shulker(), $worldName, $position, VanillaAiBehaviors::shulker(), $motion, $yaw, $pitch, $health);
        $this->setGravityEnabled(false);
    }

    public function getAttachmentFace(): BlockFace
    {
        return $this->attachmentFace;
    }

    /** @internal The authoritative attachment resolver chooses a supported face. */
    public function setAttachmentFace(BlockFace $face): void
    {
        if ($this->attachmentFace !== $face) {
            $this->attachmentFace = $face;
            $this->markPresentationChanged();
        }
    }

    public function getPeekAmount(): int
    {
        return $this->peekAmount;
    }

    /** @internal Open height is a Bedrock byte-scale value from 0 through 100. */
    public function setPeekAmount(int $amount): void
    {
        if ($amount < 0 || $amount > 100) {
            throw new InvalidArgumentException('Shulker peek amount must be between zero and 100.');
        }
        if ($this->peekAmount !== $amount) {
            $this->peekAmount = $amount;
            $this->markPresentationChanged();
        }
    }

    public function persistenceVariant(): null
    {
        return null;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'attachmentFace' => $this->attachmentFace->value,
            'peekAmount' => $this->peekAmount,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 128) {
            throw new InvalidArgumentException('Persisted shulker state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted shulker state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['attachmentFace', 'peekAmount']
            || !is_string($decoded['attachmentFace']) || !is_int($decoded['peekAmount'])) {
            throw new InvalidArgumentException('Persisted shulker state is malformed.');
        }
        $face = BlockFace::tryFrom($decoded['attachmentFace']);
        if ($face === null || $decoded['peekAmount'] < 0 || $decoded['peekAmount'] > 100) {
            throw new InvalidArgumentException('Persisted shulker state contains an invalid value.');
        }
        $this->attachmentFace = $face;
        $this->peekAmount = $decoded['peekAmount'];
    }
}
