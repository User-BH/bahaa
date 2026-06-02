<?php

declare(strict_types=1);

namespace BahaaGateway\Exceptions;

/**
 * Raised for authentication/authorization failures (HTTP 401 and 403),
 * e.g. invalid API key, bad HMAC signature, expired timestamp or missing scope.
 */
class BahaaAuthenticationException extends BahaaHttpException
{
}
