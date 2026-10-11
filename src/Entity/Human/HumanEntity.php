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

namespace Bedriox\Server\Entity\Human;

use Bedriox\Api\Entity\Controller\HumanController;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\Human;
use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Entity\Value\EmoteId;
use Bedriox\Api\Entity\Value\Motion;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** Authoritative player-shaped entity controlled exclusively by server or plugin intent. */
final class HumanEntity extends AbstractMobEntity implements Human
{
    private int $skinRevision = 0;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        private readonly string $name,
        private Skin $skin,
        private readonly World $world,
        private readonly WorldDimension $dimension,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        if ($name === '' || strlen($name) > 64 || preg_match('//u', $name) !== 1) {
            throw new InvalidArgumentException('Human name must be valid UTF-8 and bounded.');
        }
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $definition,
            $worldName,
            $position,
            new AiBehaviorDefinition(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->setAiEnabled(false);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSkin(): Skin
    {
        return $this->skin;
    }

    public function getController(): HumanController
    {
        $controller = parent::getController();
        if (!$controller instanceof HumanController) {
            throw new \LogicException('Human entity did not create a human controller.');
        }

        return $controller;
    }

    public function setSkin(Skin $skin): void
    {
        $this->getController()->setSkin($skin);
    }

    /** @internal Authoritative controller mutation. */
    public function applySkin(Skin $skin): void
    {
        if ($this->skin !== $skin) {
            $this->skin = $skin;
            if ($this->skinRevision < PHP_INT_MAX) {
                ++$this->skinRevision;
            }
            $this->markPresentationChanged();
        }
    }

    public function skinRevision(): int
    {
        return $this->skinRevision;
    }

    public function teleport(ApiPosition $position, ?string $worldName = null): void
    {
        $this->getController()->teleport($position, $worldName);
    }

    public function setRotation(float $yaw, float $pitch): void
    {
        $this->getController()->setRotation($yaw, $pitch);
    }

    public function setNameTag(string $nameTag): void
    {
        $this->getController()->setNameTag($nameTag);
    }

    public function setNameTagVisible(bool $visible): void
    {
        $this->getController()->setNameTagVisible($visible);
    }

    public function setImmobile(bool $immobile): void
    {
        $this->getController()->setImmobile($immobile);
    }

    public function setInvisible(bool $invisible): void
    {
        $this->getController()->setInvisible($invisible);
    }

    public function setScale(float $scale): void
    {
        $this->getController()->setScale($scale);
    }

    public function setGravityEnabled(bool $enabled): void
    {
        $this->getController()->setGravityEnabled($enabled);
    }

    public function swingArm(): void
    {
        $this->getController()->swingArm();
    }

    public function playEmote(EmoteId $emote): void
    {
        $this->getController()->playEmote($emote);
    }

    public function moveToward(ApiPosition $target, float $speed): void
    {
        $this->getController()->moveToward($target, $speed);
    }

    public function navigateTo(ApiPosition $target, float $speed): void
    {
        $this->getController()->navigateTo($target, $speed);
    }

    public function cancelNavigation(): void
    {
        $this->getController()->cancelNavigation();
    }

    public function lookAt(ApiPosition $target): void
    {
        $this->getController()->lookAt($target);
    }

    public function jump(): void
    {
        $this->getController()->jump();
    }

    public function stopMoving(): void
    {
        $this->getController()->stopMoving();
    }

    public function setMotion(Motion $motion): void
    {
        $this->getController()->setMotion($motion);
    }

    /** @internal Resolves a navigation intent against this human's authoritative world context. */
    public function resolveNavigationTarget(ApiPosition $target): ApiPosition
    {
        $resolved = $target->resolve($this->world, $this->getYaw(), $this->getPitch(), $this->dimension);
        if (!$resolved->world?->isSameLoad($this->world) || $resolved->dimension !== $this->dimension) {
            throw new InvalidArgumentException('Human navigation target must belong to the same world and dimension.');
        }

        return $resolved;
    }

    protected function createController(?PluginActionBuffer $actions): MobController
    {
        return new HumanEntityController($actions, $this, $this->equipmentState());
    }
}
