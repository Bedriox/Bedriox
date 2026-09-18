<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

enum LoginState
{
    case WAIT_NETWORK_REQUEST;
    case WAIT_LOGIN;
    case WAIT_CLIENT_HANDSHAKE;
    case WAIT_PACK_INFO_RESPONSE;
    case WAIT_PACK_STACK_RESPONSE;
    case LOGIN_READY;
    case CLOSED;
}
