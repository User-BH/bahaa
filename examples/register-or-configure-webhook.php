<?php

/**
 * Configuring your webhook with Bahaa Gateway.
 *
 * IMPORTANT: The official Bahaa Gateway docs (OpenAPI + structured extraction)
 * DO NOT expose any API endpoint to register/update/delete the webhook URL.
 * The webhook URL and the webhook secret are managed from the MERCHANT PANEL.
 *
 * Therefore this SDK intentionally does NOT provide getWebhook()/registerWebhook()/
 * updateWebhook()/deleteWebhook() methods — they would have to be invented, and
 * the task explicitly forbids guessing endpoints.
 *
 * What you CAN do via the API is READ your current webhook configuration, which
 * the docs say is included in the merchant profile (webhook URL/secret + flags).
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php'; // or vendor/autoload.php with Composer

use BahaaGateway\BahaaClient;
use BahaaGateway\Exceptions\BahaaException;

$client = new BahaaClient(
    getenv('BAHAA_API_KEY') ?: 'pk_your_api_key',
    getenv('BAHAA_API_SECRET') ?: 'sk_your_api_secret'
);

try {
    $profile = $client->getProfile();

    // Field names below follow the docs' description ("webhook URL/secret and flags").
    // Inspect the full profile to find the exact keys for your gateway version.
    echo "Merchant profile:" . PHP_EOL;
    echo json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    echo PHP_EOL . "Set / change your webhook URL from the Bahaa merchant panel." . PHP_EOL;
    echo "Use the webhook_secret shown there to verify incoming webhooks." . PHP_EOL;
} catch (BahaaException $e) {
    fwrite(STDERR, "Failed to read profile: {$e->getMessage()} (HTTP {$e->getHttpStatus()})" . PHP_EOL);
    exit(1);
}
