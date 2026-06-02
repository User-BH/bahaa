<?php

/**
 * Payment return (redirect_url) landing page.
 *
 * This is the page you pass as `redirect_url` when creating an invoice. After a
 * terminal state the gateway redirects the customer's browser here and appends
 * query params: ?invoice_id=...&status=...&ts=...
 *
 * SECURITY: per the official docs those redirect params are UX hints ONLY and can
 * be spoofed by anyone. NEVER fulfill an order based on them. Always re-check the
 * real invoice status server-side (and/or rely on the signed webhook). This file
 * does exactly that.
 *
 * Example invoice creation:
 *   $client->createInvoice('50.00', 'USDT', [
 *       'redirect_url' => 'https://your-shop.example/examples/redirect-return.php',
 *   ]);
 */

declare(strict_types=1);

require __DIR__ . '/../autoload.php'; // or vendor/autoload.php with Composer

use BahaaGateway\BahaaClient;
use BahaaGateway\Exceptions\BahaaException;

$client = new BahaaClient(
    getenv('BAHAA_API_KEY') ?: 'pk_your_api_key',
    getenv('BAHAA_API_SECRET') ?: 'sk_your_api_secret'
);

// --- Read the (untrusted) redirect hints ------------------------------------
$invoiceId = isset($_GET['invoice_id']) ? trim((string) $_GET['invoice_id']) : '';
$hintStatus = isset($_GET['status']) ? (string) $_GET['status'] : null; // hint only!

if ($invoiceId === '') {
    http_response_code(400);
    echo 'Missing invoice id.';
    exit;
}

// --- Verify the REAL status server-side -------------------------------------
try {
    $invoice = $client->getInvoice($invoiceId);
} catch (BahaaException $e) {
    error_log('Return page: failed to load invoice ' . $invoiceId . ': ' . $e->getMessage());
    http_response_code(502);
    echo 'Could not verify your payment right now. Please refresh in a moment.';
    exit;
}

// The invoice fields may be at the top level or nested under "data" depending on
// your gateway version; handle both.
$data = (isset($invoice['data']) && is_array($invoice['data'])) ? $invoice['data'] : $invoice;
$status = (string) ($data['status'] ?? 'unknown');

// --- Decide what to show / do based on the verified status ------------------
switch ($status) {
    case 'completed':
        // Fulfil the order here IF it is not already fulfilled (idempotently).
        // Prefer doing fulfilment in your webhook handler; this is the UX page.
        http_response_code(200);
        $title = 'Payment successful';
        $message = 'Thank you! Your payment has been confirmed.';
        break;

    case 'confirming':
        http_response_code(200);
        $title = 'Payment received';
        $message = 'We have seen your transaction and are waiting for network confirmations. '
            . 'This page will update once it is final.';
        break;

    case 'cancelled':
        http_response_code(200);
        $title = 'Payment cancelled';
        $message = 'This payment was cancelled.';
        break;

    case 'expired':
        http_response_code(200);
        $title = 'Payment expired';
        $message = 'The payment window expired. Please start a new order.';
        break;

    default:
        http_response_code(200);
        $title = 'Payment pending';
        $message = 'Your payment is still being processed.';
        break;
}

// --- Render -----------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');
$amount = htmlspecialchars((string) ($data['amount'] ?? ''), ENT_QUOTES, 'UTF-8');
$symbol = htmlspecialchars((string) ($data['symbol'] ?? ''), ENT_QUOTES, 'UTF-8');
$safeId = htmlspecialchars($invoiceId, ENT_QUOTES, 'UTF-8');
$safeStatus = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 480px; margin: 60px auto; padding: 0 20px; }
        .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; }
        .status { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #f3f4f6; font-size: 13px; }
        .muted { color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="status">Status: <?= $safeStatus ?></p>
        <p class="muted">
            Invoice: <?= $safeId ?><?= $amount !== '' ? " &middot; {$amount} {$symbol}" : '' ?>
        </p>
        <p class="muted">
            Verified server-side. The status shown in the URL is informational only.
        </p>
    </div>
</body>
</html>
