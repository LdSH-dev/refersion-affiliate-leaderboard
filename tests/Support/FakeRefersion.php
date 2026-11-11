<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory Refersion account behind Http::fake().
 *
 * Tests describe affiliates declaratively; the fake serves the OAuth token, paginated
 * GET /affiliates and GET /affiliates/{id}/custom-fields, matching the documented shapes.
 * Scripted failures can be queued per route and are consumed before normal handling.
 */
final class FakeRefersion
{
    public const API = 'https://api.refersion.test/v2026-08';

    public const TOKEN_URL = 'https://auth.refersion.test/oauth/token';

    public const LABEL = 'Display in leaderboard';

    /** @var list<array<string, mixed>> */
    private array $affiliates = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $customFields = [];

    /** @var array<string, list<PromiseInterface|callable>> route => scripted responses */
    private array $queued = [];

    public int $tokensIssued = 0;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Adds an affiliate. $flag is the value of the "Display in leaderboard" field;
     * null means the affiliate has no such field.
     */
    public function affiliate(int $id, mixed $commission, mixed $flag = null, array $overrides = []): self
    {
        $this->affiliates[] = array_replace([
            'id' => $id,
            'firstName' => "First{$id}",
            'lastName' => "Last{$id}",
            'company' => null,
            'status' => 'APPROVED',
            'performance' => ['total' => ['commission' => $commission, 'revenue' => 0]],
        ], $overrides);

        $this->customFields[$id] = [['id' => 1, 'label' => 'What is your website URL?', 'value' => 'https://example.com']];

        if ($flag !== null) {
            $this->customFields[$id][] = ['id' => 2, 'label' => self::LABEL, 'value' => $flag];
        }

        return $this;
    }

    /** Adds a raw affiliate record verbatim (for malformed-payload tests). */
    public function rawAffiliate(array $record): self
    {
        $this->affiliates[] = $record;

        return $this;
    }

    /** @param list<array<string, mixed>> $fields */
    public function customFields(int $id, array $fields): self
    {
        $this->customFields[$id] = $fields;

        return $this;
    }

    /**
     * Scripts responses for a route ("token", "list" or "fields:{id}"), consumed in order.
     */
    public function queue(string $route, PromiseInterface|callable ...$responses): self
    {
        $this->queued[$route] = [...($this->queued[$route] ?? []), ...$responses];

        return $this;
    }

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    /** @return list<int> the page numbers requested from GET /affiliates, in order */
    public function pagesRequested(): array
    {
        return $this->recorded('#/affiliates\?#', fn (Request $r) => (int) ($this->query($r)['page'] ?? 0));
    }

    /** @return list<int> affiliate ids whose custom fields were requested, in order */
    public function customFieldLookups(): array
    {
        return $this->recorded('#/affiliates/\d+/custom-fields#', function (Request $r) {
            preg_match('#/affiliates/(\d+)/custom-fields#', $r->url(), $m);

            return (int) $m[1];
        });
    }

    private function handle(Request $request): PromiseInterface
    {
        $route = $this->route($request);

        if (($this->queued[$route] ?? []) !== []) {
            $next = array_shift($this->queued[$route]);

            return is_callable($next) ? $next($request) : $next;
        }

        return match (true) {
            $route === 'token' => $this->issueToken(),
            $route === 'list' => $this->listAffiliates($request),
            str_starts_with($route, 'fields:') => $this->fields((int) substr($route, 7)),
            default => Http::response(['error' => 'Not found'], 404),
        };
    }

    private function route(Request $request): string
    {
        $url = $request->url();

        if (str_starts_with($url, self::TOKEN_URL)) {
            return 'token';
        }

        if (preg_match('#^'.preg_quote(self::API, '#').'/affiliates/(\d+)/custom-fields#', $url, $m)) {
            return "fields:{$m[1]}";
        }

        if (preg_match('#^'.preg_quote(self::API, '#').'/affiliates(\?|$)#', $url)) {
            return 'list';
        }

        return 'unknown';
    }

    private function issueToken(): PromiseInterface
    {
        $this->tokensIssued++;

        return Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => "tok-{$this->tokensIssued}"]);
    }

    private function listAffiliates(Request $request): PromiseInterface
    {
        $query = $this->query($request);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, (int) ($query['limit'] ?? 10));

        return Http::response(['data' => array_slice($this->affiliates, ($page - 1) * $limit, $limit)]);
    }

    private function fields(int $id): PromiseInterface
    {
        return array_key_exists($id, $this->customFields)
            ? Http::response(['data' => $this->customFields[$id]])
            : Http::response(['error' => 'Affiliate not found'], 404);
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return list<mixed> */
    private function recorded(string $pattern, callable $map): array
    {
        return Http::recorded(fn (Request $r) => preg_match($pattern, $r->url()) === 1)
            ->map(fn (array $pair) => $map($pair[0]))
            ->values()
            ->all();
    }
}
