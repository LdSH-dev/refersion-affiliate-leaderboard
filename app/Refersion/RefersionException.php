<?php

namespace App\Refersion;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * Any failure talking to Refersion. Laravel calls render(), so callers need no try/catch.
 */
final class RefersionException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function auth(string $message): self
    {
        return new self($message, 'upstream_auth_failed', 502);
    }

    public static function rateLimited(?int $retryAfter): self
    {
        return new self('Refersion is rate limiting requests.', 'upstream_rate_limited', 503, $retryAfter);
    }

    public static function unavailable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 'upstream_unavailable', 503, previous: $previous);
    }

    public static function badResponse(string $message): self
    {
        return new self($message, 'upstream_bad_response', 502);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]],
            $this->status,
            $this->retryAfter !== null ? ['Retry-After' => (string) $this->retryAfter] : [],
        );
    }
}
