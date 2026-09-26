<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

use Bedriox\Api\World\Position;

interface MobController extends LivingEntityController
{
    public function setAiEnabled(bool $enabled): void;

    public function moveToward(Position $target, float $speed): void;

    public function moveAway(Position $target, float $speed): void;

    public function stopMoving(): void;

    public function lookAt(Position $target): void;

    public function target(Entity $target, float $speed): void;

    public function clearTarget(): void;
}
