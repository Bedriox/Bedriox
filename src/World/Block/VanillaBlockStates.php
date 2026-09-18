<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Block;

use Bedriox\Data\CanonicalBlockState;

/** Canonical vanilla states used by the current authoritative simulation. */
final class VanillaBlockStates
{
    private function __construct() {}

    public static function air(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:air');
    }
    public static function bedrock(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:bedrock', ['infiniburn_bit' => 0]);
    }
    public static function dirt(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:dirt');
    }
    public static function grassBlock(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:grass_block');
    }
}
