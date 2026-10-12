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

namespace Bedriox\Server\Entity\Vanilla\Misc;

use Bedriox\Api\Entity\Vanilla\PrimedTnt;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Collision\SkipsEntityContact;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class PrimedTntEntity extends AbstractMobEntity implements PrimedTnt, IntrinsicEntityPersistence, SkipsEntityContact
{
    public const int DEFAULT_FUSE_TICKS = 80;
    public const int MAXIMUM_FUSE_TICKS = 32_767;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        int $fuseTicks = self::DEFAULT_FUSE_TICKS,
        private bool $underwater = false,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
    ) {
        self::validateFuse($fuseTicks);
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::primedTnt(),
            $worldName,
            $position,
            new AiBehaviorDefinition(),
            $motion,
            $yaw,
            $pitch,
        );
        $this->fuseTicks = $fuseTicks;
        $this->setAiEnabled(false);
    }

    private int $fuseTicks;

    public function getFuseTicks(): int
    {
        return $this->fuseTicks;
    }

    public function worksUnderwater(): bool
    {
        return $this->underwater;
    }

    /** @internal Vanilla priming impulse for a uniformly selected horizontal angle. */
    public static function primingMotion(float $angleRadians): EntityMotion
    {
        if (!is_finite($angleRadians)) {
            throw new InvalidArgumentException('Primed TNT motion angle must be finite.');
        }

        return new EntityMotion(-sin($angleRadians) * 0.02, 0.2, -cos($angleRadians) * 0.02);
    }

    /** @internal Authoritative priming and plugin action application only. */
    public function setFuseTicks(int $fuseTicks): void
    {
        self::validateFuse($fuseTicks);
        if ($this->fuseTicks !== $fuseTicks) {
            $this->fuseTicks = $fuseTicks;
            $this->markPresentationChanged();
        }
    }

    /** @internal Advances the fuse and reports whether detonation is due. */
    public function advanceFuse(int $ticks = 1): bool
    {
        if ($ticks < 1 || $ticks > self::MAXIMUM_FUSE_TICKS) {
            throw new InvalidArgumentException('Primed TNT fuse advancement is outside its supported bounds.');
        }
        if ($this->fuseTicks === 0) {
            return true;
        }
        $previousFuseTicks = $this->fuseTicks;
        $this->fuseTicks = max(0, $this->fuseTicks - $ticks);
        $publishFuse = $this->fuseTicks <= 5
            || intdiv($previousFuseTicks - 1, 5) !== intdiv(max(0, $this->fuseTicks - 1), 5);
        if ($publishFuse) {
            $this->markPresentationChanged();
        } else {
            $this->markChanged();
        }

        return $this->fuseTicks === 0;
    }

    /** @internal Changes the Bedrock TNT variant before the next metadata projection. */
    public function setWorksUnderwater(bool $underwater): void
    {
        if ($this->underwater !== $underwater) {
            $this->underwater = $underwater;
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
            'fuseTicks' => $this->fuseTicks,
            'underwater' => $this->underwater,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1
            || strlen($data) > EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES) {
            throw new InvalidArgumentException('Persisted primed TNT state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted primed TNT state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['fuseTicks', 'underwater']
            || !is_int($decoded['fuseTicks']) || !is_bool($decoded['underwater'])) {
            throw new InvalidArgumentException('Persisted primed TNT state is malformed.');
        }
        self::validateFuse($decoded['fuseTicks']);
        $this->fuseTicks = $decoded['fuseTicks'];
        $this->underwater = $decoded['underwater'];
    }

    private static function validateFuse(int $fuseTicks): void
    {
        if ($fuseTicks < 0 || $fuseTicks > self::MAXIMUM_FUSE_TICKS) {
            throw new InvalidArgumentException('Primed TNT fuse must be between 0 and 32767 ticks.');
        }
    }
}
