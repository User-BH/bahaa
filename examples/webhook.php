<?php

/**
 * A complete webhook endpoint for Bahaa Gateway.
 *
 * Point your merchant-panel webhook URL at this script (served over HTTPS).
 *
 * It verifies the signature, dispatches the event to your handlers, and
 * replies with an appropriate HTTP status. It never echoes secrets or
 * internal error details back to the caller.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php'; // or vendor/autoload.php with Composer

use BahaaGateway\BahaaWebhook;
use BahaaGateway\Exceptions\BahaaWebhookException;

// The webhook secret comes from your merchant panel / profile (NOT the API "sk_" secret).
$webhookSecret = getenv('BAHAA_WEBHOOK_SECRET') ?: 'whsec_your_webhook_secret';

// Tolerance (seconds) for replay protection against the X-Gateway-Timestamp header.
$webhook = new BahaaWebhook($webhookSecret, 300);

// Register handlers for the events you care about.
$webhook->on('invoice.completed', function (array $payload, array $data): void {
    // Mark the order as paid. Always treat $data['invoice_id'] as the source of truth
    // and confirm server-side before fulfilling.
    error_log('Invoice completed: ' . ($data['invoice_id'] ?? 'unknown'));
    // ... update your database here ...
});

$webhook->on('invoice.confirming', function (array $payload, array $data): void {
    error_log('Invoice confirming: ' . ($data['invoice_id'] ?? 'unknown'));
});

$webhook->on('invoice.cancelled', function (array $payload, array $data): void {
    error_log('Invoice cancelled: ' . ($data['invoice_id'] ?? 'unknown'));
});

$webhook->on('invoice.expired', function (array $payload, array $data): void {
    error_log('Invoice expired: ' . ($data['invoice_id'] ?? 'unknown'));
});

$webhook->on('invoice.amount_updated', function (array $payload, array $data): void {
    error_log('Invoice amount updated: ' . ($data['invoice_id'] ?? 'unknown'));
});

try {
    // verify() + dispatch. Reads php://input and $_SERVER by default.
    $result = $webhook->handle();

    // 200 OK for a valid webhook (even if no handler was registered for the event).
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['received' => true, 'handled' => $result['handled']]);
} catch (BahaaWebhookException $e) {
    // Verification failed. Log internally; do NOT leak details to the caller.
    error_log('Webhook verification failed: ' . $e->getMessage());

    // 400 for malformed payloads (empty body / invalid JSON); 401 otherwise.
    $message = $e->getMessage();
    $isBadRequest = str_contains($message, 'body')
        || str_contains($message, 'JSON')
        || str_contains($message, 'header');

    http_response_code($isBadRequest ? 400 : 401);
    header('Content-Type: application/json');
    echo json_encode(['received' => false]);
}
