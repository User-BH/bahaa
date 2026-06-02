<?php

declare(strict_types=1);

namespace BahaaGateway\Exceptions;

/**
 * Raised by BahaaWebhook when an incoming webhook cannot be verified:
 * missing timestamp/signature, empty or invalid body, invalid signature,
 * or a timestamp outside the allowed tolerance.
 */
class BahaaWebhookException extends BahaaException
{
}
