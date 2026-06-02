<?php

declare(strict_types=1);

namespace BahaaGateway\Exceptions;

/**
 * Raised when a resource is not found (HTTP 404), e.g. an unknown invoice,
 * withdrawal or transaction id.
 */
class BahaaNotFoundException extends BahaaHttpException
{
}
