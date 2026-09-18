<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

enum LoginFailureCode: string
{
    case UNEXPECTED_PACKET = 'unexpected_packet';
    case UNSUPPORTED_PROTOCOL = 'unsupported_protocol';
    case AUTHENTICATION_MODE = 'authentication_mode';
    case AUTHENTICATION_FAILED = 'authentication_failed';
    case ENCRYPTION_STATE = 'encryption_state';
    case INVALID_PACK_RESPONSE = 'invalid_pack_response';
    case TIMEOUT = 'timeout';
    case INPUT_LIMIT = 'input_limit';
    case EFFECT_LIMIT = 'effect_limit';
    case CRYPTOGRAPHIC_FAILURE = 'cryptographic_failure';
    case CLOCK_FAILURE = 'clock_failure';
    case CLOSED_BY_SERVER = 'closed_by_server';
}
