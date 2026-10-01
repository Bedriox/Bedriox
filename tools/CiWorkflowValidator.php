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

        if (str_contains($workflow, 'BEDRIOX_COMPONENTS_TOKEN')) {
            $errors[] = 'CI must not require a private token for public component checkouts.';
        }
        if (preg_match('/^\s*token:/mi', $workflow) === 1) {
            $errors[] = 'CI component checkouts must not override public checkout authentication.';
        }
        if (preg_match('/^\s*ssh-key:/mi', $workflow) === 1) {
            $errors[] = 'CI must not depend on deploy keys because the organization disables them.';
        }

        if (preg_match('/^\s*ref:\s*[0-9a-f]{40}\s*$/mi', $workflow) === 1) {
            $errors[] = 'CI component checkout refs must not duplicate literal commit hashes.';
        }

        $exportPosition = strpos($workflow, $exportCommand);
        $firstComponentPosition = strpos($workflow, 'repository: Bedriox/Protocol');
        if ($exportPosition !== false && $firstComponentPosition !== false && $exportPosition > $firstComponentPosition) {
            $errors[] = 'CI must export component pins before checking out public components.';
        }

        return $errors;
    }
}
