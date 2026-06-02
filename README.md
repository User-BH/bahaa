# Bahaa Gateway — PHP SDK

A small, framework-agnostic PHP SDK for the **Bahaa Gateway** Merchant API.
No Laravel, no Symfony, no Guzzle — just `ext-curl` and `ext-json`. Works in any
plain PHP project, with or without Composer.

- **PHP:** 8.1+
- **Dependencies:** `ext-curl`, `ext-json` (no third-party packages)
- **Base URL (default):** `https://gateway.bahaa.io` (overridable for self-hosted)
- **Namespace:** `BahaaGateway\`

> This SDK was implemented strictly from the official Bahaa Gateway docs
> (OpenAPI + structured extraction). Endpoints, fields, headers and events that
> are not documented were **not** invented — see
> [Ambiguities / not found in docs](#ambiguities--not-found-in-docs).

---

## Table of contents

1. [Introduction](#1-introduction)
2. [Install with Composer](#2-install-with-composer)
3. [Use without Composer](#3-use-without-composer)
4. [Create a client](#4-create-a-client)
5. [Create an invoice](#5-create-an-invoice)
6. [Get an invoice](#6-get-an-invoice)
7. [List invoices](#7-list-invoices)
8. [Cancel an invoice](#8-cancel-an-invoice)
9. [Build a payment URL](#9-build-a-payment-url)
10. [Hosted checkout (public)](#10-hosted-checkout-public)
11. [Balance](#11-balance)
12. [Withdrawals](#12-withdrawals)
13. [Transactions](#13-transactions)
14. [Webhook setup](#14-webhook-setup)
15. [Webhook verification](#15-webhook-verification)
16. [Error handling](#16-error-handling)
17. [Security notes](#17-security-notes)
18. [Self-hosted gateway base URL](#18-self-hosted-gateway-base-url)
19. [Production notes](#19-production-notes)
20. [Ambiguities / not found in docs](#ambiguities--not-found-in-docs)

---

## 1. Introduction

The SDK has two main classes:

- **`BahaaGateway\BahaaClient`** — calls the Merchant API and public endpoints.
- **`BahaaGateway\BahaaWebhook`** — verifies and dispatches incoming webhooks.

Every method returns a **plain PHP associative array** (decoded JSON), and every
failure is a typed exception extending `BahaaGateway\Exceptions\BahaaException`.

**Authentication model:**

- **Merchant endpoints** are signed with **HMAC-SHA256** (`X-API-Key`,
  `X-Timestamp`, `X-Signature`).
- **Public checkout endpoints** are browser-safe and are sent **without** any
  API key or signature.

---

## 2. Install with Composer

```bash
composer require bahaa-io/gateway-php-sdk
```

```php
require __DIR__ . '/vendor/autoload.php';

use BahaaGateway\BahaaClient;
```

## 3. Use without Composer

Copy the package somewhere in your project and require the bundled autoloader:

```php
require __DIR__ . '/bahaa-gateway-php-sdk/autoload.php';

use BahaaGateway\BahaaClient;
```

`autoload.php` registers a tiny PSR-4 autoloader for the `BahaaGateway\`
namespace — no Composer required.

## 4. Create a client

```php
use BahaaGateway\BahaaClient;

$client = new BahaaClient(
    'pk_your_api_key',     // API key (public half, pk_...)
    'sk_your_api_secret',  // API secret (sk_...) — backend only!
    30,                    // timeout in seconds (optional, default 30)
    'https://gateway.bahaa.io' // base URL (optional)
);

// Reconfigurable at runtime:
$client->setTimeout(60);
$client->setBaseUrl('https://gateway.your-domain.com');
```

## 5. Create an invoice

```php
$invoice = $client->createInvoice('50.00', 'USDT', [
    'description'     => 'Order #1234',
    'fee_payer'       => 'customer',        // "merchant" or "customer"
    'allow_networks'  => ['tron', 'ton'],
    'allow_symbols'   => ['USDT'],
    'expires_in_sec'  => 1800,              // 600..259200
    'rate_update_sec' => 600,               // 300..1800
    'redirect_url'    => 'https://shop.example/return',
    'disable_callback'=> false,
    'idempotency_key' => 'order-1234',      // sent as the Idempotency-Key header
]);

// Send the customer to the hosted payment page:
header('Location: ' . $invoice['payment_url']);
```

**Supported option keys** (documented fields only): `description`,
`allow_networks`, `allow_symbols`, `fee_payer`, `expires_in_sec`,
`rate_update_sec`, `redirect_url`, `disable_callback`, plus `idempotency_key`
(header). Any other key is ignored so the SDK never sends undocumented fields.

> **Amount precision:** the API treats money fields as decimal strings. Pass
> `amount` as a **string** (`'50.00'`) to avoid float rounding. A `float` is
> also accepted.

## 6. Get an invoice

```php
$invoice = $client->getInvoice('inv_abc123');
```

## 7. List invoices

Pagination uses **`limit` / `offset`** (per the docs), with an optional
`status` filter:

```php
$list = $client->getInvoices([
    'status' => 'completed',
    'limit'  => 10,
    'offset' => 0,
]);
```

## 8. Cancel an invoice

```php
$client->cancelInvoice('inv_abc123'); // idempotent, empty body
```

## 9. Build a payment URL

Builds the hosted checkout URL locally (no network call). Optional UX hints
`lang` and `theme`:

```php
$url = $client->paymentUrl('inv_abc123', 'en', 'light');
// https://gateway.bahaa.io/inv_abc123?lang=en&theme=light
```

### Return page after payment (`redirect_url`)

When you create an invoice with `redirect_url`, the gateway redirects the
customer's browser back to that URL after a terminal state and appends
`?invoice_id=...&status=...&ts=...`.

**Those query params are UX hints only and can be spoofed** — never fulfil an
order based on them. On the return page, re-check the real status server-side
(and rely on the signed webhook for actual fulfilment). A complete landing page
is in [`examples/redirect-return.php`](examples/redirect-return.php):

```php
$invoiceId = (string) ($_GET['invoice_id'] ?? '');
$invoice = $client->getInvoice($invoiceId); // authoritative, server-side
$status  = $invoice['status'] ?? ($invoice['data']['status'] ?? 'unknown');
// show success/pending/cancelled based on $status — not on $_GET['status']
```

> **redirect vs webhook:** the `redirect_url` page is for the *customer's UX*
> (it only fires if the browser actually comes back). Do real order fulfilment
> in your **webhook** handler (`examples/webhook.php`), which is signed and
> reliable.

## 10. Hosted checkout (public)

These map to the public, browser-safe checkout routes and are **not signed**:

```php
$client->checkoutSelect('inv_abc123', 'tron', 'USDT'); // pick network + asset
$client->checkoutStatus('inv_abc123');                 // poll status
$client->checkoutCancel('inv_abc123');                 // shopper cancellation

// Live updates over WebSocket — this SDK only builds the URL (no socket client,
// to stay dependency-free). Hand it to any WS client you like:
$wsUrl = $client->checkoutWebSocketUrl('inv_abc123');
// wss://gateway.bahaa.io/inv_abc123/ws
```

> Normally your *backend* creates the invoice and your *customer's browser*
> drives the checkout/select/status/ws routes. They are exposed here for
> server-side orchestration and testing.

## 11. Balance

```php
$balance = $client->getBalance(); // available + pending per network/asset
```

## 12. Withdrawals

```php
$wd = $client->createWithdrawal('tron', 'USDT', '25.00', 'T...address', [
    'idempotency_key' => 'payout-001', // Idempotency-Key header
]);

$client->getWithdrawal('wd_123');
$client->getWithdrawals(['limit' => 20, 'offset' => 0]);
```

Documented body fields are `network`, `symbol`, `amount`, `to_address`.
The docs do **not** define `memo` / `tag` / `comment` fields, so they are not
supported (see [Ambiguities](#ambiguities--not-found-in-docs)).

## 13. Transactions

`{id}` is the **numeric database id**, not the on-chain tx hash:

```php
$tx = $client->getTransaction(12345);
$txs = $client->getTransactions(['limit' => 20, 'offset' => 0]);
```

## 14. Webhook setup

There is **no API endpoint** to register/update/delete the webhook URL — it is
configured from the **Bahaa merchant panel**. The merchant profile exposes your
current webhook URL/secret:

```php
$profile = $client->getProfile(); // includes webhook URL/secret + flags
```

Then point that URL at a script like [`examples/webhook.php`](examples/webhook.php).

## 15. Webhook verification

> **Important divergence from a "simple" guess:** webhooks are signed with the
> merchant **`webhook_secret`** (from the panel/profile), **not** the API
> `sk_...` secret, and the signed string includes the **delivery id**.

**Headers:** `X-Gateway-Timestamp`, `X-Gateway-Delivery-Id`, `X-Gateway-Signature`
(plus `Content-Type` and `User-Agent: BahaaGateway/1.0`).

**Formula (per official docs):**

```text
expected_hex = HMAC_SHA256(
    webhook_secret,
    X-Gateway-Timestamp + "." + X-Gateway-Delivery-Id + "." + raw_body
)
```

compared against `X-Gateway-Signature` with `hash_equals()`.

```php
use BahaaGateway\BahaaWebhook;

$webhook = new BahaaWebhook('whsec_your_webhook_secret', 300); // tolerance secs

$webhook->on('invoice.completed', function (array $payload, array $data) {
    // mark order as paid using $data['invoice_id']
});

$result = $webhook->handle(); // verifies + dispatches; reads php://input & $_SERVER
// $result = ['event' => ..., 'data' => [...], 'payload' => [...], 'handled' => bool]
```

`verify()` / `handle()` throw `BahaaWebhookException` when: the body is empty,
JSON is invalid, the timestamp/signature/delivery-id header is missing, the
timestamp is outside tolerance, or the signature is invalid. Signature checks
always use `hash_equals()`, and the **raw body is verified as-received** (never
re-encoded).

**Supported events:** `invoice.confirming`, `invoice.completed`,
`invoice.cancelled`, `invoice.expired`, `invoice.amount_updated`.

## 16. Error handling

```php
use BahaaGateway\Exceptions\BahaaException;
use BahaaGateway\Exceptions\BahaaAuthenticationException;
use BahaaGateway\Exceptions\BahaaValidationException;
use BahaaGateway\Exceptions\BahaaNotFoundException;
use BahaaGateway\Exceptions\BahaaHttpException;

try {
    $invoice = $client->getInvoice('inv_missing');
} catch (BahaaNotFoundException $e) {
    // 404
} catch (BahaaValidationException $e) {
    // 400 / 422
} catch (BahaaAuthenticationException $e) {
    // 401 / 403
} catch (BahaaHttpException $e) {
    // any other HTTP error, or a cURL transport error
} catch (BahaaException $e) {
    // catch-all (invalid JSON, encoding errors, ...)
}
```

Exception status → class mapping:

| HTTP status | Exception |
|---|---|
| 401, 403 | `BahaaAuthenticationException` |
| 400, 422 | `BahaaValidationException` |
| 404 | `BahaaNotFoundException` |
| other ≥ 400 | `BahaaHttpException` |
| cURL transport error | `BahaaHttpException` |
| invalid/empty JSON on a 2xx, encoding errors | `BahaaException` |

Every exception carries: `getMessage()`, `getHttpStatus()`, `getErrorCode()`
(from the `error.code` envelope), `getResponseBody()` (raw), and
`getDecodedResponse()` (array).

## 17. Security notes

- **Never** expose the API secret (`sk_...`) in frontend/browser code. Merchant
  endpoints must be called from your **backend** only.
- Public checkout routes are different from the merchant API — they are
  browser-safe and intentionally unsigned.
- **Always** verify webhook signatures. Use the `webhook_secret`, not the API
  secret, and keep the default replay `tolerance`.
- The **raw webhook body must not be modified** before verification (don't
  re-encode it). This SDK signs/verifies the exact received bytes.
- Use **HTTPS** for your webhook URL.
- Do **not** print secrets or internal error details in HTTP responses — the
  bundled `examples/webhook.php` follows this.

## 18. Self-hosted gateway base URL

```php
$client = new BahaaClient('pk_...', 'sk_...', 30, 'https://pay.your-domain.com');
// or
$client->setBaseUrl('https://pay.your-domain.com');
```

`checkoutWebSocketUrl()` automatically derives `wss://` (or `ws://`) from your
base URL.

## 19. Production notes

- Store `pk_`/`sk_`/`whsec_` in environment variables or a secrets manager.
- Send an `Idempotency-Key` on invoice/withdrawal creation to make retries safe.
- Treat webhooks and redirect params as hints — **confirm invoice status
  server-side** before fulfilling an order.
- TLS verification is enabled by default (`CURLOPT_SSL_VERIFYPEER`).
- Money fields are JSON **strings** for precision; keep them as strings in your
  own code and avoid float math.
- Set a sensible `timeout` for your environment.

---

## Ambiguities / not found in docs

These items were requested in the original task but are **not present** in the
official docs provided, so they were deliberately handled conservatively rather
than guessed:

1. **`getRates($base)` — NOT FOUND.** There is no `/api/v1/rates` (or similar)
   endpoint in the docs. The method was **not** implemented. Exchange-rate data
   appears only indirectly via `/api/v1/health` and invoice quoting
   (`rate_update_sec`, `invoice.amount_updated`).

2. **`getSymbols($network)` filter — partly undocumented.** `GET /api/v1/symbols`
   is documented, but a `network` query filter is **not**. The method accepts an
   optional `$network` and forwards it as a `network` query param on a
   best-effort basis; the gateway may ignore it.

3. **Webhook registration endpoints — NOT FOUND.** The docs expose **no** API
   route to register/update/delete a webhook URL. Therefore
   `getWebhook()/registerWebhook()/updateWebhook()/deleteWebhook()` were **not**
   created. The webhook URL/secret is managed from the merchant panel and is
   readable via `getProfile()`. See `examples/register-or-configure-webhook.php`.

4. **Invoice fields differ from the task draft.** The docs define
   `expires_in_sec`, `rate_update_sec`, `redirect_url` and `disable_callback`.
   They do **not** define `metadata`, `callback_url`, `ttl`, or `expires_at`,
   so those were **not** implemented. (`disable_callback` toggles HTTP webhooks
   per invoice; `redirect_url` is the terminal browser redirect.)

5. **Merchant signing string has 5 parts, not 4.** The task draft suggested
   `timestamp\nMETHOD\nPATH\nBODY`. The official docs require **`QUERY`** between
   `PATH` and `RAW_BODY`:
   `timestamp\nMETHOD\nPATH\nQUERY\nRAW_BODY` (lowercase-hex HMAC-SHA256, GET has
   an empty body, QUERY excludes the leading `?`). The official format is used.

6. **Webhook signature differs from the task draft.** The draft suggested
   `HMAC(api_secret, timestamp + "." + raw_body)`. The official docs use the
   **`webhook_secret`** and include the **delivery id**:
   `HMAC(webhook_secret, timestamp + "." + delivery_id + "." + raw_body)`.
   Consequently `isValidSignature()` takes an extra `$deliveryId` argument
   (`isValidSignature($timestamp, $deliveryId, $signature, $rawBody)`), and the
   `BahaaWebhook` constructor argument is the **webhook secret**.

7. **Extra webhook event.** Beyond the four requested events, the docs also
   define `invoice.amount_updated` (quote refreshed before payment), which is
   supported.

8. **Withdrawal `memo`/`tag`/`comment` — NOT FOUND.** Only `network`, `symbol`,
   `amount`, `to_address` (body) and `Idempotency-Key` (header) are documented,
   so only `idempotency_key` is supported in `$options`.

9. **Response field names** (e.g. `payment_url`, `invoice_id`) follow the docs'
   descriptions; the docs do not pin every exact response key, so inspect the
   returned array for your gateway version. All methods return the decoded JSON
   verbatim.

> **Official sources used:** the provided OpenAPI draft (`v1.4.2`) and the
> structured docs extraction from `gateway.bahaa.io`. The public
> `https://bahaa.io/docs` site and the official GitHub/Packagist SDK pages were
> not reachable from this build environment, so nothing was inferred from them.
```
