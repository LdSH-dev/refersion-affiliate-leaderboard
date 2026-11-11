<?php

namespace App\Refersion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two Refersion endpoints the leaderboard needs (docs/SPEC.md §3), with OAuth,
 * retries and error mapping. Methods return raw decoded records.
 */
final class RefersionClient
{
    /** @return list<mixed> GET /affiliates?page=&limit= */
    public function affiliates(int $page, int $limit): array
    {
        $response = $this->get(['list' => ['/affiliates', ['page' => $page, 'limit' => $limit]]])['list'];

        return $this->data($response, "GET /affiliates?page={$page}");
    }

    /**
     * GET /affiliates/{id}/custom-fields for several affiliates concurrently.
     *
     * @param  list<int>  $ids
     * @return array<int, list<mixed>> keyed by affiliate id
     */
    public function customFields(array $ids): array
    {
        $requests = [];
        foreach ($ids as $id) {
            $requests[$id] = ["/affiliates/{$id}/custom-fields", []];
        }

        $fields = [];
        foreach ($this->get($requests) as $id => $response) {
            // Listed a moment ago but deleted since: it can't be shown, so it isn't eligible.
            $fields[$id] = $response instanceof Response && $response->status() === 404
                ? []
                : $this->data($response, "GET /affiliates/{$id}/custom-fields");
        }

        return $fields;
    }

    /**
     * Sends the GETs concurrently. A 401 means the cached token expired early: get a new one
     * and send once more.
     *
     * @param  array<array-key, array{0: string, 1: array<string, mixed>}>  $requests  key => [path, query]
     * @return array<array-key, Response|Throwable>
     */
    private function get(array $requests): array
    {
        $send = fn (string $token) => Http::pool(function (Pool $pool) use ($requests, $token) {
            foreach ($requests as $key => [$path, $query]) {
                $this->configure($pool->as((string) $key))
                    ->baseUrl((string) config('refersion.base_url'))
                    ->withToken($token)
                    ->get($path, $query);
            }
        });

        $responses = $send($this->token());

        if (array_any($responses, fn (mixed $r) => $r instanceof Response && $r->status() === 401)) {
            $responses = $send($this->token(refresh: true));
        }

        return $responses;
    }

    private function token(bool $refresh = false): string
    {
        $key = 'refersion:token:'.sha1(config('refersion.token_url').'|'.config('refersion.client_id'));

        if ($refresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (blank(config('refersion.client_id')) || blank(config('refersion.client_secret'))) {
            throw RefersionException::auth('Refersion credentials are not configured (REFERSION_CLIENT_ID / REFERSION_CLIENT_SECRET).');
        }

        try {
            // short_lived on purpose: asking for long_lived revokes the merchant's existing one.
            $response = $this->configure(Http::asForm())->post((string) config('refersion.token_url'), [
                'client_id' => config('refersion.client_id'),
                'client_secret' => config('refersion.client_secret'),
                'token_type' => 'short_lived',
            ]);
        } catch (ConnectionException $e) {
            $this->fail($e, 'POST /oauth/token');
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw RefersionException::auth('Refersion rejected the client credentials.');
        }

        if (! $response->successful()) {
            $this->fail($response, 'POST /oauth/token');
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw RefersionException::badResponse('Refersion token response has no access_token.');
        }

        $expiresIn = $response->json('expires_in');
        Cache::put($key, $token, max(1, (is_int($expiresIn) ? $expiresIn : 3600) - 60));

        return $token;
    }

    /**
     * Timeouts and retries shared by every call: connection errors, 5xx and 429 are retried
     * with backoff (or after Retry-After). A Retry-After too long to wait for fails fast.
     */
    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->acceptJson()
            ->connectTimeout((float) config('refersion.connect_timeout'))
            ->timeout((float) config('refersion.timeout'))
            ->retry(
                (int) config('refersion.retry_attempts'),
                fn (int $attempt, Throwable $e) => $this->retryAfter($e) * 1000 ?: 200 * 2 ** ($attempt - 1),
                fn (Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError())
                    || ($e instanceof RequestException && $e->response->status() === 429
                        && $this->retryAfter($e) <= (int) config('refersion.max_retry_after')),
                throw: false,
            );
    }

    private function retryAfter(Throwable|Response $from): int
    {
        $response = $from instanceof RequestException ? $from->response : $from;
        $header = $response instanceof Response ? $response->header('Retry-After') : '';

        return ctype_digit($header) ? (int) $header : 0;
    }

    /** @return list<mixed> */
    private function data(Response|Throwable $response, string $what): array
    {
        if ($response instanceof Throwable || ! $response->successful()) {
            $this->fail($response, $what);
        }

        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw RefersionException::badResponse("{$what} returned a payload without a \"data\" list.");
        }

        return $data;
    }

    private function fail(Response|Throwable $response, string $what): never
    {
        if ($response instanceof Throwable) {
            throw RefersionException::unavailable("Could not reach Refersion ({$what}).", $response);
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            throw RefersionException::auth("Refersion rejected the access token (HTTP {$status}).");
        }

        if ($status === 429) {
            throw RefersionException::rateLimited($this->retryAfter($response) ?: null);
        }

        if ($response->serverError()) {
            throw RefersionException::unavailable("{$what} returned HTTP {$status}.");
        }

        // The upstream body goes to the log only: it is free-form and could echo request details.
        Log::warning('Refersion returned a client error', ['request' => $what, 'status' => $status, 'body' => mb_substr($response->body(), 0, 200)]);

        throw RefersionException::badResponse("{$what} returned HTTP {$status}.");
    }
}
