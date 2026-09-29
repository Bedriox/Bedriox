# Particles

Bedriox exposes particles as typed, presentation-only world actions. Particle requests never mutate blocks, entities, inventories, effects, or any other authoritative gameplay state.

The API covers both particle forms used by the current Bedrock client:

- `SimpleParticle` selects one of the 116 current named `minecraft:*` resource effects and may include bounded MoLang variables.
- Standard, scalar, colored, block, item-break, dragon-egg teleport, and mob-spawn particles carry typed data for the older level-event presentation path. Callers never provide protocol event IDs or packed integers.

```php
use Bedriox\Api\World\Particle\ParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Api\World\Position;

$world->spawnParticle(
    new Position(12.5, 65.0, -4.5),
    new SimpleParticle(ParticleType::FLAME),
);
```

Passing `null` as the audience selects every connected player in the world who has received the particle's chunk. A list of player snapshots narrows that audience; Bedriox still removes offline players, players from another world load, and players who have not received the chunk.

```php
$world->spawnParticle($position, new SimpleParticle(ParticleType::HEART), [$player]);
```

Some named Bedrock particle effects accept bounded MoLang variables. Variable names must use the `variable.*` namespace, and values must be scalar.

```php
use Bedriox\Api\World\Particle\ParticleVariables;

$particle = new SimpleParticle(
    ParticleType::SPLASH_SPELL,
    new ParticleVariables([
        'variable.tint_r' => 1.0,
        'variable.tint_g' => 0.25,
        'variable.tint_b' => 0.5,
    ]),
);
```

Requests enter the authoritative world command queue. The queue, audience, variable payload, per-plugin work, per-world work, and final directed-packet fan-out are bounded. Requests beyond the current tick's budget are rejected instead of delaying gameplay traffic.

Payload-bearing particles keep their inputs explicit:

```php
use Bedriox\Api\World\BlockFace;
use Bedriox\Api\World\Particle\BlockParticle;
use Bedriox\Api\World\Particle\BlockParticleType;
use Bedriox\Api\World\Particle\ColoredParticle;
use Bedriox\Api\World\Particle\ColoredParticleType;
use Bedriox\Api\World\Particle\ParticleBlockState;
use Bedriox\Api\World\Particle\ParticleColor;

$world->spawnParticle(
    $position,
    new ColoredParticle(ColoredParticleType::DUST, new ParticleColor(255, 96, 32)),
);
$world->spawnParticle(
    $position,
    new BlockParticle(
        BlockParticleType::PUNCH,
        new ParticleBlockState('minecraft:oak_log', ['pillar_axis' => 'y']),
        BlockFace::UP,
    ),
);
```

`ItemBreakParticle` accepts a canonical API `ItemStack`. `DragonEggTeleportParticle` carries signed offsets, while `MobSpawnParticle` carries bounded width and height. Bedriox resolves canonical item and block data against the active data set only at the packet boundary.
