<?php

declare(strict_types=1);

namespace Bedriox\Tests\Api;

use Bedriox\Api\Player\GameMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameMode::class)]
final class PlayerGameModeTest extends TestCase
{
    public function testCapabilitiesAreDerivedFromTheAuthoritativeMode(): void
    {
        self::assertTrue(GameMode::SURVIVAL->canBuild());
        self::assertTrue(GameMode::SURVIVAL->consumesItems());
        self::assertTrue(GameMode::SURVIVAL->takesDamage());
        self::assertFalse(GameMode::SURVIVAL->allowsFlight());

        self::assertTrue(GameMode::CREATIVE->canBuild());
        self::assertTrue(GameMode::CREATIVE->instantlyBreaksBlocks());
        self::assertTrue(GameMode::CREATIVE->allowsFlight());
        self::assertFalse(GameMode::CREATIVE->consumesItems());
        self::assertFalse(GameMode::CREATIVE->takesDamage());

        self::assertFalse(GameMode::ADVENTURE->canBuild());
        self::assertTrue(GameMode::ADVENTURE->takesDamage());
        self::assertFalse(GameMode::ADVENTURE->allowsFlight());

        self::assertFalse(GameMode::SPECTATOR->canBuild());
        self::assertTrue(GameMode::SPECTATOR->allowsFlight());
        self::assertFalse(GameMode::SPECTATOR->hasCollision());
        self::assertFalse(GameMode::SPECTATOR->isVisible());
        self::assertFalse(GameMode::SPECTATOR->takesDamage());
    }
}
