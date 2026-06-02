<?php

/**
 * Basic usage: create a client and call a few endpoints.
 *
 * Run from the project root:  php examples/basic-usage.php
 */

declare(strict_types=1);

// With Composer:
//   require __DIR__ . '/../vendor/autoload.php';
// Without Composer:
require __DIR__ . '/../autoload.php';

use BahaaGateway\BahaaClient;
use BahaaGateway\Exceptions\BahaaAuthenticationException;
use BahaaGateway\Exceptions\BahaaException;

// NEVER hard-code secrets in source. Read them from the environment instead.
$apiKey = getenv('BAHAA_API_KEY') ?: 'pk_your_api_key';
$apiSecret = getenv('BAHAA_API_SECRET') ?: 'sk_your_api_secret';

// For a self-hosted gateway, pass your own base URL as the 4th argument.
$client = new BahaaClient($apiKey, $apiSecret, 30, BahaaClient::DEFAULT_BASE_URL);

try {
    // Public endpoints (no authentication required).
    $health = $client->health();
    echo "Health: " . json_encode($health, JSON_PRETTY_PRINT) . PHP_EOL;

    $networks = $client->getNetworks();
    echo "Networks: " . json_encode($networks, JSON_PRETTY_PRINT) . PHP_EOL;

    $symbols = $client->getSymbols();
    echo "Symbols: " . json_encode($symbols, JSON_PRETTY_PRINT) . PHP_EOL;

    // Merchant endpoint (signed with HMAC).
    $profile = $client->getProfile();
    echo "Profile: " . json_encode($profile, JSON_PRETTY_PRINT) . PHP_EOL;

    $balance = $client->getBalance();
    echo "Balance: " . json_encode($balance, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (BahaaAuthenticationException $e) {
    fwrite(STDERR, "Authentication failed: {$e->getMessage()} (code: {$e->getErrorCode()})" . PHP_EOL);
    exit(1);
} catch (BahaaException $e) {
    fwrite(STDERR, "Bahaa error: {$e->getMessage()} (HTTP {$e->getHttpStatus()})" . PHP_EOL);
    exit(1);
}
