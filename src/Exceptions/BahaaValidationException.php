<?php

declare(strict_types=1);

namespace BahaaGateway\Exceptions;

/**
 * Raised for validation errors (HTTP 400 and 422), e.g. malformed request
 * body or invalid field values.
 */
class BahaaValidationException extends BahaaHttpException
{
}
