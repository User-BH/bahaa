<?php

declare(strict_types=1);

namespace BahaaGateway\Exceptions;

/**
 * Raised for HTTP error responses (status >= 400) that are not mapped to a
 * more specific exception, and for transport-level (cURL) failures.
 */
class BahaaHttpException extends BahaaException
{
}
