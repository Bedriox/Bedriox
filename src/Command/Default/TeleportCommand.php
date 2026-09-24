<?php

declare(strict_types=1);

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\PlayerCommandSender;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use Closure;

final readonly class TeleportCommand implements BuiltinCommand
{
    /** @param Closure(Player, Position, ?float, ?float): bool|null $teleport */
    public function __construct(
        private OnlinePlayerResolver $players,
        private ?Closure $teleport = null,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'tp',
            'Teleports a player to another player or a position.',
            'tp [player] <destination|x y z> [yaw pitch]',
            aliases: ['teleport'],
            permission: 'bedriox.command.teleport',
        );
    }

    public function execute(CommandContext $context): CommandResult
    {
        $arguments = $context->arguments();
        $subjectName = match (count($arguments)) {
            1, 3, 5 => null,
            2, 4, 6 => array_shift($arguments),
            default => false,
        };
        if ($subjectName === false) {
            return CommandResult::USAGE;
        }
        $subject = $subjectName === null
            ? ($context->sender() instanceof PlayerCommandSender ? $context->sender()->player() : null)
            : $this->players->find($subjectName);
        if ($subject === null) {
            if ($subjectName === null) {
                return CommandResult::USAGE;
            }
            $context->sender()->sendMessage('Player is not online.');

            return CommandResult::FAILURE;
        }
        if ($subjectName !== null && !$context->sender()->hasPermission('bedriox.command.teleport.other')) {
            $context->sender()->sendMessage('You do not have permission to teleport other players.');

            return CommandResult::FAILURE;
        }

        if (count($arguments) === 1) {
            $destination = $this->players->find($arguments[0]);
            if ($destination === null) {
                $context->sender()->sendMessage('Destination player is not online.');

                return CommandResult::FAILURE;
            }
            if (!$this->queue($subject, $destination->position, $destination->yaw, $destination->pitch)) {
                $context->sender()->sendMessage('Unable to teleport the player.');

                return CommandResult::FAILURE;
            }
            $context->sender()->sendMessage("Teleported {$subject->name} to {$destination->name}.");

            return CommandResult::SUCCESS;
        }

        $position = $this->coordinates($subject->position, array_slice($arguments, 0, 3));
        if ($position === null) {
            $context->sender()->sendMessage('Coordinates must be finite numbers or relative values such as ~ or ~2.5.');

            return CommandResult::FAILURE;
        }
        $yaw = $subject->yaw;
        $pitch = $subject->pitch;
        if (count($arguments) === 5) {
            $yaw = $this->orientation($arguments[3], $subject->yaw, -360.0, 360.0);
            $pitch = $this->orientation($arguments[4], $subject->pitch, -90.0, 90.0);
            if ($yaw === null || $pitch === null) {
                $context->sender()->sendMessage('Yaw or pitch is outside its supported range.');

                return CommandResult::FAILURE;
            }
        }
        if (!$this->queue($subject, $position, $yaw, $pitch)) {
            $context->sender()->sendMessage('Unable to teleport the player.');

            return CommandResult::FAILURE;
        }
        $context->sender()->sendMessage(sprintf(
            'Teleported %s to %.2f, %.2f, %.2f.',
            $subject->name,
            $position->x,
            $position->y,
            $position->z,
        ));

        return CommandResult::SUCCESS;
    }

    /** @param list<string> $coordinates */
    private function coordinates(Position $base, array $coordinates): ?Position
    {
        if (count($coordinates) !== 3) {
            return null;
        }
        $x = $this->coordinate($coordinates[0], $base->x, -30_000_000.0, 30_000_000.0);
        $y = $this->coordinate($coordinates[1], $base->y, -64.0, 319.0);
        $z = $this->coordinate($coordinates[2], $base->z, -30_000_000.0, 30_000_000.0);

        return $x === null || $y === null || $z === null ? null : new Position($x, $y, $z);
    }

    private function orientation(string $value, float $base, float $minimum, float $maximum): ?float
    {
        return $this->coordinate($value, $base, $minimum, $maximum);
    }

    private function coordinate(string $value, float $base, float $minimum, float $maximum): ?float
    {
        $relative = str_starts_with($value, '~');
        $number = $relative ? substr($value, 1) : $value;
        if ($number === '') {
            $number = '0';
        }
        if (!is_numeric($number)) {
            return null;
        }
        $result = (float) $number + ($relative ? $base : 0.0);

        return is_finite($result) && $result >= $minimum && $result <= $maximum ? $result : null;
    }

    private function queue(Player $subject, Position $position, float $yaw, float $pitch): bool
    {
        return $this->teleport !== null && ($this->teleport)($subject, $position, $yaw, $pitch);
    }
}
