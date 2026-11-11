<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeRefersion;
use Tests\TestCase;

/** The two ways to see the board: the web page and the CLI. */
final class InterfaceTest extends TestCase
{
    #[Test]
    public function the_page_mounts_the_react_app(): void
    {
        $this->withoutVite()->get('/')->assertOk()->assertSee('<div id="app">', false);
    }

    #[Test]
    public function the_cli_prints_a_table_or_a_json_error(): void
    {
        $fake = FakeRefersion::make()
            ->affiliate(1, 1234567.5, 'TRUE')
            ->affiliate(2, 10, 'TRUE', ['firstName' => '<href=https://evil.test>Click</>', 'lastName' => "\e[31mRed"])
            ->install();

        $this->artisan('leaderboard:show')
            ->expectsOutputToContain('1,234,567.50')
            ->expectsOutputToContain('<href=https://evil.test>Click</> [31mRed')
            ->expectsOutputToContain('2 affiliates · 1 page(s) · 2 custom-field lookup(s)')
            ->assertExitCode(0);

        $fake->queue('list', ...array_fill(0, 3, fn () => Http::response([], 500)));
        $this->artisan('leaderboard:show --json --fresh')->expectsOutputToContain('"code": "upstream_unavailable"')->assertExitCode(1);
    }
}
