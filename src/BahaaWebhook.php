<?php

declare(strict_types=1);

namespace BahaaGateway;

use BahaaGateway\Exceptions\BahaaWebhookException;

/**
 * Receives, verifies and dispatches Bahaa Gateway webhooks.
 *
 * IMPORTANT (per official docs): webhook signatures are computed with the
 * merchant's *webhook_secret* (NOT the API token secret "sk_..."). The
 * webhook_secret is available on the merchant profile / merchant panel.
 *
 * Verification (per official docs):
 *   expected_hex = HMAC_SHA256(
 *       webhook_secret,
 *       X-Gateway-Timestamp + "." + X-Gateway-Delivery-Id + "." + raw_body
 *   )
 *   compare with the X-Gateway-Signature header using hash_equals().
 *
 * Relevant request headers:
 *   - X-Gateway-Timestamp    Unix seconds
 *   - X-Gateway-Delivery-Id  unique delivery id (part of the signed string)
 *   - X-Gateway-Signature    lowercase hex HMAC-SHA256
 *   - User-Agent: BahaaGateway/1.0
 */
class BahaaWebhook
{
    /** Webhook events documented by the gateway. */
    public const EVENTS = [
        'invoice.confirming',
        'invoice.completed',
        'invoice.cancelled',
        'invoice.expired',
        'invoice.amount_updated',
    ];

    private string $webhookSecret;
    private int $tolerance;

    /** @var array<string, callable[]> */
    private array $listeners = [];

    /**
     * @param string $webhookSecret The merchant's webhook secret (NOT the API "sk_" secret).
     * @param int    $tolerance     Max allowed age of a webhook in seconds (replay protection).
     */
    public function __construct(string $webhookSecret, int $tolerance = 300)
    {
        $this->webhookSecret = $webhookSecret;
        $this->tolerance = $tolerance;
    }

    /**
     * Register a callback for a webhook event.
     *
     * The callback receives ($payload, $data) where $payload is the full decoded
     * envelope and $data is $payload['data'] (or [] when absent).
     *
     * @param callable(array,array):mixed $callback
     */
    public function on(string $event, callable $callback): self
    {
        $this->listeners[$event][] = $callback;

        return $this;
    }

    /**
     * Verify an incoming webhook and return its decoded payload.
     *
     * @param string|null $rawBody Raw request body. Defaults to php://input.
     * @param array|null  $headers Request headers. Defaults to $_SERVER.
     *
     * @return array The decoded JSON payload (associative array).
     *
     * @throws BahaaWebhookException when verification fails for any reason.
     */
    public function verify(?string $rawBody = null, ?array $headers = null): array
    {
        if ($rawBody === null) {
            $rawBody = (string) file_get_contents('php://input');
        }
        if ($headers === null) {
            $headers = $_SERVER;
        }

        if ($rawBody === '') {
            throw new BahaaWebhookException('Empty webhook body.');
        }

        $timestamp = $this->header($headers, 'X-Gateway-Timestamp');
        $deliveryId = $this->header($headers, 'X-Gateway-Delivery-Id');
        $signature = $this->header($headers, 'X-Gateway-Signature');

        if ($timestamp === null || $timestamp === '') {
            throw new BahaaWebhookException('Missing X-Gateway-Timestamp header.');
        }
        if ($signature === null || $signature === '') {
            throw new BahaaWebhookException('Missing X-Gateway-Signature header.');
        }
        // Delivery id is part of the signed string; without it we cannot verify.
        if ($deliveryId === null || $deliveryId === '') {
            throw new BahaaWebhookException('Missing X-Gateway-Delivery-Id header.');
        }

        // Reject timestamps outside the allowed tolerance (replay protection).
        if (!ctype_digit(ltrim($timestamp, '-')) || abs(time() - (int) $timestamp) > $this->tolerance) {
            throw new BahaaWebhookException('Webhook timestamp is outside the allowed tolerance.');
        }

        if (!$this->isValidSignature($timestamp, $deliveryId, $signature, $rawBody)) {
            throw new BahaaWebhookException('Invalid webhook signature.');
        }

        $payload = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !\is_array($payload)) {
            throw new BahaaWebhookException('Invalid webhook JSON payload: ' . json_last_error_msg());
        }

        return $payload;
    }

    /**
     * Constant-time check of a webhook signature.
     *
     * NOTE: the official signing string includes the delivery id, so this
     * method requires $deliveryId in addition to the fields named in the SDK
     * task spec. See README for the exact formula.
     */
    public function isValidSignature(
        string $timestamp,
        string $deliveryId,
        string $signature,
        string $rawBody
    ): bool {
        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $deliveryId . '.' . $rawBody,
            $this->webhookSecret
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Verify the webhook and dispatch it to any registered listener.
     *
     * @return array{event:?string, data:array, payload:array, handled:bool}
     *
     * @throws BahaaWebhookException when verification fails.
     */
    public function handle(?string $rawBody = null, ?array $headers = null): array
    {
        $payload = $this->verify($rawBody, $headers);

        $event = isset($payload['event']) ? (string) $payload['event'] : null;
        $data = (isset($payload['data']) && \is_array($payload['data'])) ? $payload['data'] : [];

        $handled = false;
        if ($event !== null && !empty($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $callback) {
                $callback($payload, $data);
            }
            $handled = true;
        }

        return [
            'event' => $event,
            'data' => $data,
            'payload' => $payload,
            'handled' => $handled,
        ];
    }

    /**
     * Look up a header value case-insensitively, supporting both a normalised
     * headers array (e.g. from getallheaders()) and the $_SERVER super-global
     * (HTTP_X_GATEWAY_TIMESTAMP style keys).
     */
    private function header(array $headers, string $name): ?string
    {
        // Direct / case-insensitive match (normalised header arrays).
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return \is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        // $_SERVER style: X-Gateway-Timestamp -> HTTP_X_GATEWAY_TIMESTAMP
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($headers[$serverKey])) {
            return (string) $headers[$serverKey];
        }

        return null;
    }
}
