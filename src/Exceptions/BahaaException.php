<?php

declare(strict_types=1);

namespace BahaaGateway\Exceptions;

/**
 * Base exception for every error raised by the Bahaa Gateway SDK.
 *
 * All other SDK exceptions extend this class, so catching BahaaException
 * is enough to handle any failure originating from the SDK.
 */
class BahaaException extends \Exception
{
    /** HTTP status code returned by the gateway (null for transport/local errors). */
    protected ?int $httpStatus;

    /** Machine readable error code from the gateway error envelope (error.code). */
    protected ?string $errorCode;

    /** Raw, untouched response body as received from the gateway. */
    protected ?string $responseBody;

    /** Decoded response body as an associative array, when JSON was valid. */
    protected ?array $decodedResponse;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $responseBody = null,
        ?array $decodedResponse = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
        $this->decodedResponse = $decodedResponse;
    }

    /** HTTP status code, or null for local/transport errors. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** Gateway error code (error.code), or null when absent. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Raw response body exactly as received. */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /** Decoded response body (associative array) when JSON was valid. */
    public function getDecodedResponse(): ?array
    {
        return $this->decodedResponse;
    }
}
