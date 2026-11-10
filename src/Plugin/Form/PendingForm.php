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

namespace Bedriox\Server\Plugin\Form;

use Bedriox\Api\Form\Form;
use Bedriox\Api\Form\FormState;
use Bedriox\Server\Plugin\PluginExecutionFrame;

final class PendingForm
{
    public FormState $state = FormState::QUEUED;

    public function __construct(
        public readonly int $id,
        public readonly Form $form,
        public readonly ?PluginExecutionFrame $owner,
        public readonly string $resourceId,
        public readonly int $createdAtNanoseconds,
    ) {}
}
