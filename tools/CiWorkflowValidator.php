<?php

declare(strict_types=1);

namespace Bedriox\Tools;

final class CiWorkflowValidator
{
    /** @return list<string> */
    public static function validate(string $workflow): array
    {
        $errors = [];
        $exportCommand = 'php tools/export-component-pins.php';
        if (substr_count($workflow, $exportCommand) !== 1) {
            $errors[] = 'CI must export component commits from bedriox.lock.json exactly once.';
        }
        if (substr_count($workflow, 'id: component-pins') !== 1) {
            $errors[] = 'CI must expose one component-pins step.';
        }

        foreach (['protocol', 'raknet', 'data'] as $component) {
            $reference = 'ref: ${{ steps.component-pins.outputs.' . $component . '_commit }}';
            if (substr_count($workflow, $reference) !== 1) {
                $errors[] = "CI must check out {$component} from the component-pins output.";
            }
        }

        if (preg_match('/^\s*ref:\s*[0-9a-f]{40}\s*$/mi', $workflow) === 1) {
            $errors[] = 'CI component checkout refs must not duplicate literal commit hashes.';
        }

        $exportPosition = strpos($workflow, $exportCommand);
        $firstComponentPosition = strpos($workflow, 'repository: Bedriox/Protocol');
        if ($exportPosition !== false && $firstComponentPosition !== false && $exportPosition > $firstComponentPosition) {
            $errors[] = 'CI must export component pins before checking out private components.';
        }

        return $errors;
    }
}
