<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

enum OutboundCompressionAdmission: string
{
    case ACCEPTED = 'accepted';
    case SATURATED = 'saturated';
    case CLOSED = 'closed';
}
