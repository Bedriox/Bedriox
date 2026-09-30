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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\SheepController;
use Bedriox\Api\Entity\Vanilla\Sheep;
use Bedriox\Api\Entity\WoolColor;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\AnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Plugin\BufferedSheepController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;
use LogicException;

final class SheepEntity extends AnimalEntity implements Sheep, IntrinsicEntityPersistence
{
    public const int MAXIMUM_LOVE_TICKS = 600;
    public const int BABY_GROWTH_TICKS = 24_000;
    public const int BREEDING_COOLDOWN_TICKS = 6_000;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?AiBehaviorDefinition $behavior = null,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        private bool $baby = false,
        private WoolColor $woolColor = WoolColor::WHITE,
        private bool $sheared = false,
        private int $loveTicks = 0,
        private int $babyGrowthTicks = 0,
        private int $breedingCooldownTicks = 0,
    ) {
        if ($baby && $babyGrowthTicks === 0) {
            $babyGrowthTicks = self::BABY_GROWTH_TICKS;
        }
        self::validateState($baby, $sheared, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
        $this->babyGrowthTicks = $babyGrowthTicks;
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::sheep(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::sheep(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
    }

    public function isBaby(): bool
    {
        return $this->baby;
    }

    public function isSheared(): bool
    {
        return $this->sheared;
    }

    public function getWoolColor(): WoolColor
    {
        return $this->woolColor;
    }

    public function getLoveTicks(): int
    {
        return $this->loveTicks;
    }

    public function isReadyToBreed(): bool
    {
        return !$this->baby && $this->loveTicks > 0 && $this->breedingCooldownTicks === 0;
    }

    public function getController(): SheepController
    {
        $controller = parent::getController();
        if (!$controller instanceof SheepController) {
            throw new LogicException('A sheep must expose a sheep controller.');
        }

        return $controller;
    }

    /** @internal Authoritative species-state mutation. */
    public function setBaby(bool $baby): void
    {
        self::validateState(
            $baby,
            $this->sheared,
            $baby ? 0 : $this->loveTicks,
            $baby ? self::BABY_GROWTH_TICKS : 0,
            $this->breedingCooldownTicks,
        );
        if ($this->baby !== $baby) {
            $this->baby = $baby;
            $this->babyGrowthTicks = $baby ? self::BABY_GROWTH_TICKS : 0;
            if ($baby) {
                $this->loveTicks = 0;
            }
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setSheared(bool $sheared): void
    {
        self::validateState(
            $this->baby,
            $sheared,
            $this->loveTicks,
            $this->babyGrowthTicks,
            $this->breedingCooldownTicks,
        );
        if ($this->sheared !== $sheared) {
            $this->sheared = $sheared;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setWoolColor(WoolColor $color): void
    {
        if ($this->woolColor !== $color) {
            $this->woolColor = $color;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative breeding-state mutation. */
    public function setLoveTicks(int $ticks): void
    {
        self::validateState(
            $this->baby,
            $this->sheared,
            $ticks,
            $this->babyGrowthTicks,
            $this->breedingCooldownTicks,
        );
        if ($this->loveTicks !== $ticks) {
            $this->loveTicks = $ticks;
            $this->markChanged();
        }
    }

    /** @internal Advances bounded species timers once per simulation tick. */
    public function advanceSpeciesState(int $ticks = 1): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Sheep species-state advance is outside its supported bound.');
        }
        $changed = false;
        if ($this->loveTicks > 0) {
            $this->loveTicks = max(0, $this->loveTicks - $ticks);
            $changed = true;
        }
        if ($this->breedingCooldownTicks > 0) {
            $this->breedingCooldownTicks = max(0, $this->breedingCooldownTicks - $ticks);
            $changed = true;
        }
        if ($this->babyGrowthTicks > 0) {
            $this->babyGrowthTicks = max(0, $this->babyGrowthTicks - $ticks);
            $changed = true;
            if ($this->babyGrowthTicks === 0) {
                $this->baby = false;
                $this->markPresentationChanged();

                return;
            }
        }
        if ($changed) {
            $this->markChanged();
        }
    }

    /** @internal Commits a completed breeding cycle. */
    public function beginBreedingCooldown(): void
    {
        $this->loveTicks = 0;
        $this->breedingCooldownTicks = self::BREEDING_COOLDOWN_TICKS;
        $this->markChanged();
    }

    /** @internal Applies a bounded growth boost from authoritative feeding. */
    public function accelerateGrowth(int $ticks): void
    {
        if (!$this->baby || $ticks < 1 || $ticks > self::BABY_GROWTH_TICKS) {
            throw new InvalidArgumentException('Sheep growth acceleration is outside its supported bounds.');
        }
        $this->babyGrowthTicks = max(0, $this->babyGrowthTicks - $ticks);
        if ($this->babyGrowthTicks === 0) {
            $this->baby = false;
            $this->markPresentationChanged();

            return;
        }
        $this->markChanged();
    }

    public function persistenceVariant(): string
    {
        return $this->woolColor->value;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'baby' => $this->baby,
            'babyGrowthTicks' => $this->babyGrowthTicks,
            'breedingCooldownTicks' => $this->breedingCooldownTicks,
            'loveTicks' => $this->loveTicks,
            'sheared' => $this->sheared,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_string($variant) || $schemaVersion !== 1 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted sheep state has an unsupported schema.');
        }
        $color = WoolColor::tryFrom($variant);
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted sheep state is malformed.', previous: $error);
        }
        if ($color === null || !is_array($decoded)
            || array_keys($decoded) !== ['baby', 'babyGrowthTicks', 'breedingCooldownTicks', 'loveTicks', 'sheared']
            || !is_bool($decoded['baby']) || !is_int($decoded['babyGrowthTicks'])
            || !is_int($decoded['breedingCooldownTicks']) || !is_int($decoded['loveTicks'])
            || !is_bool($decoded['sheared'])) {
            throw new InvalidArgumentException('Persisted sheep state is malformed.');
        }
        self::validateState(
            $decoded['baby'],
            $decoded['sheared'],
            $decoded['loveTicks'],
            $decoded['babyGrowthTicks'],
            $decoded['breedingCooldownTicks'],
        );
        $this->baby = $decoded['baby'];
        $this->babyGrowthTicks = $decoded['babyGrowthTicks'];
        $this->breedingCooldownTicks = $decoded['breedingCooldownTicks'];
        $this->loveTicks = $decoded['loveTicks'];
        $this->sheared = $decoded['sheared'];
        $this->woolColor = $color;
    }

    private static function validateState(
        bool $baby,
        bool $sheared,
        int $loveTicks,
        int $babyGrowthTicks,
        int $breedingCooldownTicks,
    ): void {
        if ($baby && $sheared) {
            throw new InvalidArgumentException('A baby sheep cannot be sheared.');
        }
        if ($loveTicks < 0 || $loveTicks > self::MAXIMUM_LOVE_TICKS || ($baby && $loveTicks !== 0)
            || $babyGrowthTicks < 0 || $babyGrowthTicks > self::BABY_GROWTH_TICKS
            || ($baby !== ($babyGrowthTicks > 0))
            || $breedingCooldownTicks < 0 || $breedingCooldownTicks > self::BREEDING_COOLDOWN_TICKS) {
            throw new InvalidArgumentException('Sheep love state is outside its supported bounds.');
        }
    }

    protected function createController(?PluginActionBuffer $actions): SheepController
    {
        return new BufferedSheepController($actions, $this, $this->equipmentState());
    }

    protected function sizeMultiplier(): float
    {
        return $this->baby ? 0.5 : 1.0;
    }
}
