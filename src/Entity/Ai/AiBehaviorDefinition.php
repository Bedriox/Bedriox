<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

use InvalidArgumentException;

final readonly class AiBehaviorDefinition
{
    public const int MAXIMUM_SENSORS = 32;
    public const int MAXIMUM_GOALS = 64;

    /** @var list<AiSensor> */
    public array $sensors;

    /** @var list<AiGoal> */
    public array $goals;

    /**
     * @param array<array-key, mixed> $sensors
     * @param array<array-key, mixed> $goals
     */
    public function __construct(array $sensors = [], array $goals = [])
    {
        if (!array_is_list($sensors) || count($sensors) > self::MAXIMUM_SENSORS
            || !array_is_list($goals) || count($goals) > self::MAXIMUM_GOALS) {
            throw new InvalidArgumentException('AI definition exceeds its supported collection bounds.');
        }
        $sensors = self::validateSensors($sensors);
        $goals = self::validateGoals($goals);
        usort($goals, static fn(AiGoal $left, AiGoal $right): int =>
            ($right->priority() <=> $left->priority()) ?: ($left->identifier() <=> $right->identifier()));
        $this->sensors = $sensors;
        $this->goals = $goals;
    }

    /**
     * @param list<mixed> $definitions
     * @return list<AiSensor>
     */
    private static function validateSensors(array $definitions): array
    {
        $validated = [];
        $identifiers = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof AiSensor) {
                throw new InvalidArgumentException('AI sensor definition has an invalid type.');
            }
            $identifier = $definition->identifier();
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
                || isset($identifiers[$identifier])) {
                throw new InvalidArgumentException('AI sensor identifier is invalid or duplicated.');
            }
            $identifiers[$identifier] = true;
            $interval = $definition->intervalTicks();
            if ($interval < 1 || $interval > 1_200) {
                throw new InvalidArgumentException('AI sensor interval is outside its supported bounds.');
            }
            $validated[] = $definition;
        }

        return $validated;
    }

    /**
     * @param list<mixed> $definitions
     * @return list<AiGoal>
     */
    private static function validateGoals(array $definitions): array
    {
        $validated = [];
        $identifiers = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof AiGoal) {
                throw new InvalidArgumentException('AI goal definition has an invalid type.');
            }
            $identifier = $definition->identifier();
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1
                || isset($identifiers[$identifier])) {
                throw new InvalidArgumentException('AI goal identifier is invalid or duplicated.');
            }
            $identifiers[$identifier] = true;
            if ($definition->evaluationIntervalTicks() < 1 || $definition->evaluationIntervalTicks() > 1_200) {
                throw new InvalidArgumentException('AI goal interval is outside its supported bounds.');
            }
            if ($definition->priority() < -32_768 || $definition->priority() > 32_767) {
                throw new InvalidArgumentException('AI goal priority is outside its supported range.');
            }
            $controls = $definition->controls();
            if (count($controls) > count(AiControl::cases())) {
                throw new InvalidArgumentException('AI goal controls are outside their supported bounds.');
            }
            $seenControls = [];
            foreach ($controls as $control) {
                if (isset($seenControls[$control->value])) {
                    throw new InvalidArgumentException('AI goal controls cannot contain duplicates.');
                }
                $seenControls[$control->value] = true;
            }
            $validated[] = $definition;
        }

        return $validated;
    }
}
