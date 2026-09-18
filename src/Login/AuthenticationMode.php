<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

enum AuthenticationMode
{
    case FULL;
    case SELF_SIGNED;
}
