<?php

declare(strict_types=1);

namespace Bedriox\Server\Transport\Process;

enum TransportProcessFrameKind: int
{
    case READY = 1;
    case SEND_PAYLOAD = 2;
    case REMOVE_SESSION = 3;
    case SHUTDOWN = 4;
    case SESSION_OPENED = 5;
    case SESSION_CLOSED = 6;
    case RECEIVED_PAYLOAD = 7;
    case HANDSHAKE_DIAGNOSTICS = 8;
    case FAILURE = 9;
}
