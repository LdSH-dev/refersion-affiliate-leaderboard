<?php

namespace Tests\Feature;

use App\Leaderboard\LeaderboardService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeRefersion;
use Tests\TestCase;

final class ErrorHandlingTest extends TestCase
{
    public static function upstreamFailures(): array
    {
        $always = fn (int $status, array $headers = []) => array_fill(0, 6, fn () => Http::response(['error' => 'upstream detail'], $status, $headers));

        return [
            '401 twice' => [$always(401), 502, 'upstream_auth_failed'],
            'other 4xx' => [$always(422), 502, 'upstream_bad_response'],
            '500 after retries' => [$always(500), 503, 'upstream_unavailable'],
            'connection error' => [array_fill(0, 3, fn () => throw new ConnectionException('down')), 503, 'upstream_unavailable'],
        ];
    }

    #[Test]
    #[DataProvider('upstreamFailures')]
    public function upstream_failures_map_to_a_documented_error(array $responses, int $status, string $code): void
    {
        FakeRefersion::make()->queue('list', ...$responses)->install();

        $body = $this->getJson('/api/leaderboard')->assertStatus($status)->assertJsonPath('error.code', $code)->getContent();

        $this->assertStringNotContainsString('upstream detail', $body, 'upstream text is logged, never returned');
    }

    #[Test]
    public function transient_failures_are_retried(): void
    {
        $fake = FakeRefersion::make()
            ->affiliate(1, 10, 'TRUE')
            ->queue('list', fn () => Http::response([], 503), fn () => Http::response([], 502))
            ->install();

        $this->getJson('/api/leaderboard')->assertOk()->assertJsonPath('data.0.id', 1);
        $this->assertSame([1, 1, 1], $fake->pagesRequested());
    }

    #[Test]
    public function a_long_retry_after_fails_fast_and_is_passed_on(): void
    {
        $fake = FakeRefersion::make()->queue('list', fn () => Http::response([], 429, ['Retry-After' => '60']))->install();

        $this->getJson('/api/leaderboard')->assertStatus(503)
            ->assertJsonPath('error.code', 'upstream_rate_limited')
            ->assertHeader('Retry-After', '60');
        $this->assertSame([1], $fake->pagesRequested());
    }

    #[Test]
    public function an_expired_token_is_replaced_once(): void
    {
        $fake = FakeRefersion::make()
            ->affiliate(1, 10, 'TRUE')
            ->queue('list', fn () => Http::response(['error' => 'Unauthenticated'], 401))
            ->install();

        $this->getJson('/api/leaderboard')->assertOk();
        $this->assertSame(2, $fake->tokensIssued);
    }

    #[Test]
    public function a_failed_custom_field_lookup_fails_the_board_instead_of_skipping_the_affiliate(): void
    {
        FakeRefersion::make()
            ->affiliate(1, 20, 'TRUE')->affiliate(2, 10, 'TRUE')
            ->queue('fields:1', ...array_fill(0, 3, fn () => Http::response([], 500)))
            ->install();

        $this->getJson('/api/leaderboard')->assertStatus(503)->assertJsonPath('error.code', 'upstream_unavailable');
    }

    #[Test]
    public function other_api_errors_use_the_same_shape_without_internals(): void
    {
        config(['app.debug' => true]);
        $this->app->bind(LeaderboardService::class, fn () => throw new RuntimeException('SQLSTATE secret'));

        $this->getJson('/api/leaderboard')->assertStatus(500)
            ->assertExactJson(['error' => ['code' => 'internal_error', 'message' => 'Internal server error.']]);
        $this->getJson('/api/nope')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->postJson('/api/leaderboard')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }
}
