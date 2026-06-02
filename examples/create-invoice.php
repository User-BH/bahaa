<?php

/**
 * Create an invoice and redirect the customer to the hosted payment page.
 *
 * In a web context this would typically run on your checkout/order controller.
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php'; // or vendor/autoload.php with Composer

use BahaaGateway\BahaaClient;
use BahaaGateway\Exceptions\BahaaException;
use BahaaGateway\Exceptions\BahaaValidationException;

$client = new BahaaClient(
    getenv('BAHAA_API_KEY') ?: 'pk_your_api_key',
    getenv('BAHAA_API_SECRET') ?: 'sk_your_api_secret'
);

try {
    // Amount is passed as a string to preserve decimal precision (recommended).
    $invoice = $client->createInvoice('50.00', 'USDT', [
        'description'     => 'Order #1234',
        'fee_payer'       => 'customer',          // "merchant" or "customer"
        'allow_networks'  => ['tron', 'ton'],     // optional allowlist
        'allow_symbols'   => ['USDT'],            // optional allowlist
        'expires_in_sec'  => 1800,                // 600..259200
        'rate_update_sec' => 600,                 // 300..1800
        'redirect_url'    => 'https://your-shop.example/checkout/return',
        // 'disable_callback' => true,            // disable HTTP webhooks for this invoice
        'idempotency_key' => 'order-1234',        // sent as Idempotency-Key header
    ]);

    echo "Invoice created:" . PHP_EOL;
    echo json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    // The gateway returns a hosted payment_url. Prefer it when present.
    $paymentUrl = $invoice['payment_url']
        ?? ($invoice['data']['payment_url'] ?? null);

    // You can also build the hosted checkout URL yourself from the invoice id:
    if ($paymentUrl === null) {
        $invoiceId = $invoice['invoice_id'] ?? ($invoice['data']['invoice_id'] ?? null);
        if ($invoiceId !== null) {
            $paymentUrl = $client->paymentUrl($invoiceId, 'en', 'light');
        }
    }

    if ($paymentUrl !== null && PHP_SAPI !== 'cli') {
        header('Location: ' . $paymentUrl);
        exit;
    }

    echo "Payment URL: " . ($paymentUrl ?? '(not available)') . PHP_EOL;
} catch (BahaaValidationException $e) {
    fwrite(STDERR, "Validation error: {$e->getMessage()}" . PHP_EOL);
    fwrite(STDERR, "Details: " . json_encode($e->getDecodedResponse()) . PHP_EOL);
    exit(1);
} catch (BahaaException $e) {
    fwrite(STDERR, "Failed to create invoice: {$e->getMessage()} (HTTP {$e->getHttpStatus()})" . PHP_EOL);
    exit(1);
}
