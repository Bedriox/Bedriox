<?php

declare(strict_types=1);

namespace Bedriox\Api;

/** Bedrock text colors and styles for player-facing messages. */
final class TextFormat
{
    public const string ESCAPE = "\u{00a7}";

    public const string BLACK = self::ESCAPE . '0';
    public const string DARK_BLUE = self::ESCAPE . '1';
    public const string DARK_GREEN = self::ESCAPE . '2';
    public const string DARK_AQUA = self::ESCAPE . '3';
    public const string DARK_RED = self::ESCAPE . '4';
    public const string DARK_PURPLE = self::ESCAPE . '5';
    public const string GOLD = self::ESCAPE . '6';
    public const string GRAY = self::ESCAPE . '7';
    public const string DARK_GRAY = self::ESCAPE . '8';
    public const string BLUE = self::ESCAPE . '9';
    public const string GREEN = self::ESCAPE . 'a';
    public const string AQUA = self::ESCAPE . 'b';
    public const string RED = self::ESCAPE . 'c';
    public const string LIGHT_PURPLE = self::ESCAPE . 'd';
    public const string YELLOW = self::ESCAPE . 'e';
    public const string WHITE = self::ESCAPE . 'f';
    public const string MINECOIN_GOLD = self::ESCAPE . 'g';
    public const string MATERIAL_QUARTZ = self::ESCAPE . 'h';
    public const string MATERIAL_IRON = self::ESCAPE . 'i';
    public const string MATERIAL_NETHERITE = self::ESCAPE . 'j';
    public const string MATERIAL_REDSTONE = self::ESCAPE . 'm';
    public const string MATERIAL_COPPER = self::ESCAPE . 'n';
    public const string MATERIAL_GOLD = self::ESCAPE . 'p';
    public const string MATERIAL_EMERALD = self::ESCAPE . 'q';
    public const string MATERIAL_DIAMOND = self::ESCAPE . 's';
    public const string MATERIAL_LAPIS = self::ESCAPE . 't';
    public const string MATERIAL_AMETHYST = self::ESCAPE . 'u';
    public const string MATERIAL_RESIN = self::ESCAPE . 'v';

    public const string OBFUSCATED = self::ESCAPE . 'k';
    public const string BOLD = self::ESCAPE . 'l';
    public const string ITALIC = self::ESCAPE . 'o';
    public const string RESET = self::ESCAPE . 'r';

    public const array COLORS = [
        self::BLACK,
        self::DARK_BLUE,
        self::DARK_GREEN,
        self::DARK_AQUA,
        self::DARK_RED,
        self::DARK_PURPLE,
        self::GOLD,
        self::GRAY,
        self::DARK_GRAY,
        self::BLUE,
        self::GREEN,
        self::AQUA,
        self::RED,
        self::LIGHT_PURPLE,
        self::YELLOW,
        self::WHITE,
        self::MINECOIN_GOLD,
        self::MATERIAL_QUARTZ,
        self::MATERIAL_IRON,
        self::MATERIAL_NETHERITE,
        self::MATERIAL_REDSTONE,
        self::MATERIAL_COPPER,
        self::MATERIAL_GOLD,
        self::MATERIAL_EMERALD,
        self::MATERIAL_DIAMOND,
        self::MATERIAL_LAPIS,
        self::MATERIAL_AMETHYST,
        self::MATERIAL_RESIN,
    ];

    public const array FORMATS = [self::OBFUSCATED, self::BOLD, self::ITALIC];

    private function __construct() {}
}
