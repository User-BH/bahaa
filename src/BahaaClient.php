<?php

declare(strict_types=1);

namespace BahaaGateway;

use BahaaGateway\Exceptions\BahaaAuthenticationException;
use BahaaGateway\Exceptions\BahaaException;
use BahaaGateway\Exceptions\BahaaHttpException;
use BahaaGateway\Exceptions\BahaaNotFoundException;
use BahaaGateway\Exceptions\BahaaValidationException;

/**
 * Framework-agnostic PHP client for the Bahaa Gateway Merchant API.
 *
 * Requires: ext-curl, ext-json. PHP >= 8.1.
 *
 * Merchant endpoints are authenticated with HMAC-SHA256 using the headers
 * X-API-Key, X-Timestamp and X-Signature. Public checkout endpoints are
 * browser-safe and are sent without any authentication headers.
 *
 * Signing string (per official docs):
 *   timestamp + "\n" + METHOD + "\n" + PATH + "\n" + QUERY + "\n" + RAW_BODY
 *   - PATH excludes scheme/host and the leading "?"
 *   - QUERY is the raw query string without the leading "?", empty when none
 *   - RAW_BODY is the exact bytes sent; GET uses an empty body
 * Signature: lowercase hex of HMAC_SHA256(api_secret, signing_string)
 */
class BahaaClient
{
    public const DEFAULT_BASE_URL = 'https://gateway.bahaa.io';

    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        int $timeout = 30,
        string $baseUrl = self::DEFAULT_BASE_URL
    ) {
        if (!\extension_loaded('curl')) {
            throw new BahaaException('The "curl" PHP extension is required by the Bahaa SDK.');
        }
        if (!\extension_loaded('json')) {
            throw new BahaaException('The "json" PHP extension is required by the Bahaa SDK.');
        }

        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->timeout = $timeout;
        $this->setBaseUrl($baseUrl);
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        // Normalise: drop any trailing slashes so we can safely concatenate paths.
        $this->baseUrl = rtrim($baseUrl, '/');

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ---------------------------------------------------------------------
    // Public endpoints (no authentication)
    // ---------------------------------------------------------------------

    /** GET /api/v1/health — gateway/database/rate/watcher health and build metadata. */
    public function health(): array
    {
        return $this->request('GET', '/api/v1/health', [], false);
    }

    /** GET /api/v1/networks — supported chains with confirmations and finality estimates. */
    public function getNetworks(): array
    {
        return $this->request('GET', '/api/v1/networks', [], false);
    }

    /**
     * GET /api/v1/symbols — supported assets/symbols, min amount and withdrawal fee metadata.
     *
     * NOTE: The official docs do not document a "network" filter on this endpoint.
     * When $network is provided it is forwarded as a "network" query parameter on a
     * best-effort basis; the gateway may ignore it. See README "Ambiguities".
     */
    public function getSymbols(?string $network = null): array
    {
        $query = $network !== null ? ['network' => $network] : [];

        return $this->request('GET', '/api/v1/symbols', $query, false);
    }

    // ---------------------------------------------------------------------
    // Merchant: profile
    // ---------------------------------------------------------------------

    /** GET /api/v1/merchant/profile — merchant record (display name, fee, webhook URL/secret, flags). */
    public function getProfile(): array
    {
        return $this->request('GET', '/api/v1/merchant/profile');
    }

    // ---------------------------------------------------------------------
    // Merchant: invoices
    // ---------------------------------------------------------------------

    /**
     * POST /api/v1/invoices — create a payable invoice and receive a hosted payment_url.
     *
     * Supported $options (documented fields only):
     *   - description      string  optional checkout text
     *   - allow_networks   array   optional string[] allowlist of networks
     *   - allow_symbols    array   optional string[] allowlist of symbols
     *   - fee_payer        string  "merchant" or "customer"
     *   - expires_in_sec   int     600..259200; 0/omit uses default
     *   - rate_update_sec  int     300..1800; 0/omit uses default
     *   - redirect_url     string  terminal redirect (appends invoice_id,status,ts)
     *   - disable_callback bool    disables HTTP invoice webhooks for this invoice
     *   - idempotency_key  string  sent as the "Idempotency-Key" header (not part of body)
     *
     * Any other key in $options is ignored to avoid sending undocumented fields.
     *
     * @param string|float $amount Decimal amount. Pass a string to preserve precision.
     */
    public function createInvoice(string|float $amount, string $symbol, array $options = []): array
    {
        $body = ['amount' => $amount, 'symbol' => $symbol];

        $allowed = [
            'description',
            'allow_networks',
            'allow_symbols',
            'fee_payer',
            'expires_in_sec',
            'rate_update_sec',
            'redirect_url',
            'disable_callback',
        ];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $options)) {
                $body[$key] = $options[$key];
            }
        }

        $headers = [];
        if (!empty($options['idempotency_key'])) {
            $headers['Idempotency-Key'] = (string) $options['idempotency_key'];
        }

        return $this->request('POST', '/api/v1/invoices', $body, true, $headers);
    }

    /** GET /api/v1/invoices/{id} — full detail for a single invoice. */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', '/api/v1/invoices/' . rawurlencode($invoiceId));
    }

    /**
     * GET /api/v1/invoices — list invoices with pagination and optional status filter.
     *
     * Supported $filters (documented): status, limit (default 10), offset (default 0).
     */
    public function getInvoices(array $filters = []): array
    {
        return $this->request('GET', '/api/v1/invoices', $filters);
    }

    /** POST /api/v1/invoices/{id}/cancel — idempotent merchant cancellation (empty body). */
    public function cancelInvoice(string $invoiceId): array
    {
        return $this->request('POST', '/api/v1/invoices/' . rawurlencode($invoiceId) . '/cancel');
    }

    /**
     * Build the hosted checkout (payment) URL for an invoice.
     *
     * Corresponds to the public checkout page GET /{invoice_id}. Optional UX hints
     * "lang" and "theme" are appended as query parameters when provided. This does
     * not perform a network request; it only assembles the URL.
     */
    public function paymentUrl(string $invoiceId, ?string $lang = null, ?string $theme = null): string
    {
        $query = [];
        if ($lang !== null) {
            $query['lang'] = $lang;
        }
        if ($theme !== null) {
            $query['theme'] = $theme;
        }

        $url = $this->baseUrl . '/' . rawurlencode($invoiceId);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    // ---------------------------------------------------------------------
    // Public hosted checkout (browser-safe, NOT signed)
    // ---------------------------------------------------------------------

    /** POST /{invoice_id}/select — select network and asset while pending; assigns a deposit wallet. */
    public function checkoutSelect(string $invoiceId, string $network, string $symbol): array
    {
        return $this->request(
            'POST',
            '/' . rawurlencode($invoiceId) . '/select',
            ['network' => $network, 'symbol' => $symbol],
            false
        );
    }

    /** GET /{invoice_id}/status — poll lightweight checkout status. */
    public function checkoutStatus(string $invoiceId): array
    {
        return $this->request('GET', '/' . rawurlencode($invoiceId) . '/status', [], false);
    }

    /** POST /{invoice_id}/cancel — shopper cancellation while pending/awaiting_payment. */
    public function checkoutCancel(string $invoiceId): array
    {
        return $this->request('POST', '/' . rawurlencode($invoiceId) . '/cancel', [], false);
    }

    /**
     * Build the WebSocket URL for live checkout updates (GET /{invoice_id}/ws).
     *
     * This SDK does not open the socket itself (no dependencies). Pass the returned
     * wss:// (or ws://) URL to any WebSocket client of your choice. See README.
     */
    public function checkoutWebSocketUrl(string $invoiceId): string
    {
        $wsBase = preg_replace('#^https://#i', 'wss://', $this->baseUrl);
        $wsBase = preg_replace('#^http://#i', 'ws://', (string) $wsBase);

        return $wsBase . '/' . rawurlencode($invoiceId) . '/ws';
    }

    // ---------------------------------------------------------------------
    // Merchant: balance
    // ---------------------------------------------------------------------

    /** GET /api/v1/balance — per-network/per-asset balances (available and pending). */
    public function getBalance(): array
    {
        return $this->request('GET', '/api/v1/balance');
    }

    // ---------------------------------------------------------------------
    // Merchant: withdrawals
    // ---------------------------------------------------------------------

    /**
     * POST /api/v1/withdrawals — create an on-chain payout/withdrawal.
     *
     * Documented body fields: network, symbol, amount, to_address.
     * Supported $options:
     *   - idempotency_key string  sent as the "Idempotency-Key" header (not part of body)
     *
     * NOTE: The official docs do not document memo/tag/comment fields for
     * withdrawals, so they are not supported here. See README "Ambiguities".
     *
     * @param string|float $amount Decimal amount. Pass a string to preserve precision.
     */
    public function createWithdrawal(
        string $network,
        string $symbol,
        string|float $amount,
        string $address,
        array $options = []
    ): array {
        $body = [
            'network' => $network,
            'symbol' => $symbol,
            'amount' => $amount,
            'to_address' => $address,
        ];

        $headers = [];
        if (!empty($options['idempotency_key'])) {
            $headers['Idempotency-Key'] = (string) $options['idempotency_key'];
        }

        return $this->request('POST', '/api/v1/withdrawals', $body, true, $headers);
    }

    /** GET /api/v1/withdrawals/{id} — single withdrawal by id for status polling. */
    public function getWithdrawal(string $withdrawalId): array
    {
        return $this->request('GET', '/api/v1/withdrawals/' . rawurlencode($withdrawalId));
    }

    /**
     * GET /api/v1/withdrawals — paginated withdrawal history.
     *
     * Supported $filters (documented): limit (default 20), offset (default 0).
     */
    public function getWithdrawals(array $filters = []): array
    {
        return $this->request('GET', '/api/v1/withdrawals', $filters);
    }

    // ---------------------------------------------------------------------
    // Merchant: transactions
    // ---------------------------------------------------------------------

    /**
     * GET /api/v1/transactions/{id} — fetch one transaction by its numeric
     * database id (NOT the on-chain tx hash).
     */
    public function getTransaction(string|int $transactionId): array
    {
        return $this->request('GET', '/api/v1/transactions/' . rawurlencode((string) $transactionId));
    }

    /**
     * GET /api/v1/transactions — on-chain transaction ledger.
     *
     * Supported $filters (documented): limit (default 20), offset (default 0).
     */
    public function getTransactions(array $filters = []): array
    {
        return $this->request('GET', '/api/v1/transactions', $filters);
    }

    // ---------------------------------------------------------------------
    // HMAC signing
    // ---------------------------------------------------------------------

    /**
     * Build the lowercase-hex HMAC-SHA256 signature for a merchant request.
     *
     * The signing string is, per the official docs:
     *   timestamp + "\n" + METHOD + "\n" + PATH + "\n" + QUERY + "\n" + RAW_BODY
     */
    protected function sign(
        string $timestamp,
        string $method,
        string $path,
        string $query,
        string $body
    ): string {
        $signingString = $timestamp . "\n"
            . strtoupper($method) . "\n"
            . $path . "\n"
            . $query . "\n"
            . $body;

        return hash_hmac('sha256', $signingString, $this->apiSecret);
    }

    // ---------------------------------------------------------------------
    // Core request
    // ---------------------------------------------------------------------

    /**
     * Perform an HTTP request against the gateway and return the decoded array.
     *
     * @param string $method   HTTP method (GET, POST, ...).
     * @param string $path     Path beginning with "/" (no scheme/host).
     * @param array  $data     For GET/HEAD: query parameters. Otherwise: JSON body.
     * @param bool   $signed   When true, add merchant HMAC headers.
     * @param array  $headers  Extra request headers (e.g. Idempotency-Key).
     *
     * @throws BahaaException for transport/JSON errors.
     * @throws BahaaHttpException (and subclasses) for HTTP error statuses.
     */
    protected function request(
        string $method,
        string $path,
        array $data = [],
        bool $signed = true,
        array $headers = []
    ): array {
        $method = strtoupper($method);
        $isBodyMethod = !\in_array($method, ['GET', 'HEAD'], true);

        $query = '';
        $body = '';

        if ($isBodyMethod) {
            // Only emit a body when there is data; the gateway expects an empty
            // body for endpoints documented as "Empty body" (e.g. cancel).
            if ($data !== []) {
                $body = $this->encodeJson($data);
            }
        } elseif ($data !== []) {
            $query = http_build_query($data);
        }

        $url = $this->baseUrl . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        $requestHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($signed) {
            $timestamp = (string) time();
            $signature = $this->sign($timestamp, $method, $path, $query, $body);
            $requestHeaders[] = 'X-API-Key: ' . $this->apiKey;
            $requestHeaders[] = 'X-Timestamp: ' . $timestamp;
            $requestHeaders[] = 'X-Signature: ' . $signature;
        }

        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        [$status, $responseBody] = $this->execute($method, $url, $body, $requestHeaders, $isBodyMethod);

        return $this->handleResponse($status, $responseBody);
    }

    /**
     * Execute the cURL request.
     *
     * @return array{0:int,1:string} [http status, raw body]
     *
     * @throws BahaaHttpException on transport-level failures.
     */
    protected function execute(
        string $method,
        string $url,
        string $body,
        array $headers,
        bool $isBodyMethod
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            throw new BahaaHttpException('Failed to initialise cURL handle.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($isBodyMethod) {
            // Always set the body (empty string is valid) so Content-Length is correct.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
            curl_close($ch);

            throw new BahaaHttpException(
                sprintf('cURL transport error (%d): %s', $errNo, $errMsg),
                $errNo
            );
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $responseBody];
    }

    /**
     * Decode the response body and convert error statuses to exceptions.
     *
     * @throws BahaaException|BahaaHttpException
     */
    protected function handleResponse(int $status, string $body): array
    {
        $decoded = null;

        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = null;
                // Invalid JSON on an otherwise successful response is a hard error.
                if ($status >= 200 && $status < 300) {
                    throw new BahaaException(
                        'Failed to decode JSON response: ' . json_last_error_msg(),
                        0,
                        $status,
                        null,
                        $body
                    );
                }
            }
        }

        if ($status >= 400) {
            $this->throwForStatus($status, $body, \is_array($decoded) ? $decoded : null);
        }

        // Successful response. Empty body is allowed and yields an empty array.
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Map an HTTP error status to the appropriate exception and throw it.
     *
     * @throws BahaaHttpException
     */
    protected function throwForStatus(int $status, string $body, ?array $decoded): void
    {
        $errorCode = null;
        $message = null;

        if ($decoded !== null && isset($decoded['error']) && \is_array($decoded['error'])) {
            $errorCode = isset($decoded['error']['code']) ? (string) $decoded['error']['code'] : null;
            $message = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : null;
        }

        if ($message === null || $message === '') {
            $message = sprintf('HTTP %d error from Bahaa Gateway.', $status);
        }

        $args = [$message, $status, $status, $errorCode, $body, $decoded];

        switch (true) {
            case $status === 401 || $status === 403:
                throw new BahaaAuthenticationException(...$args);
            case $status === 400 || $status === 422:
                throw new BahaaValidationException(...$args);
            case $status === 404:
                throw new BahaaNotFoundException(...$args);
            default:
                throw new BahaaHttpException(...$args);
        }
    }

    /**
     * JSON-encode a request body without unwanted escaping/mutation.
     *
     * @throws BahaaException when encoding fails.
     */
    protected function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new BahaaException('Failed to encode request body as JSON: ' . json_last_error_msg());
        }

        return $json;
    }
}
