<?php

namespace Tests\Feature;

use App\Leaderboard\LeaderboardService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeRefersion;
use Tests\TestCase;

final class LeaderboardTest extends TestCase
{
    #[Test]
    public function it_ranks_the_top_five_eligible_affiliates_and_stops_looking_early(): void
    {
        $fake = FakeRefersion::make()
            ->affiliate(1, 900, 'true')        // richest, but not eligible
            ->affiliate(2, 800, 'TRUE')
            ->affiliate(3, 700, null)
            ->affiliate(4, 600, 'TRUE')
            ->affiliate(5, 600, 'TRUE')        // ties with 4: lower id first
            ->affiliate(6, 500, 'TRUE', ['firstName' => '', 'lastName' => '', 'company' => 'Acme'])
            ->affiliate(7, 400, 'TRUE')
            ->affiliate(8, 300, 'TRUE')
            ->affiliate(9, 200, 'TRUE')
            ->affiliate(10, 100, 'TRUE')
            ->affiliate(11, 50, 'TRUE')         // below the winners: never looked up
            ->install();

        $this->getJson('/api/leaderboard')->assertOk()->assertExactJson([
            'data' => [
                ['rank' => 1, 'id' => 2, 'name' => 'First2 Last2', 'commission' => '800.00'],
                ['rank' => 2, 'id' => 4, 'name' => 'First4 Last4', 'commission' => '600.00'],
                ['rank' => 3, 'id' => 5, 'name' => 'First5 Last5', 'commission' => '600.00'],
                ['rank' => 4, 'id' => 6, 'name' => 'Acme', 'commission' => '500.00'],
                ['rank' => 5, 'id' => 7, 'name' => 'First7 Last7', 'commission' => '400.00'],
            ],
            'meta' => [
                'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'affiliates_scanned' => 11,
                'pages_fetched' => 1,
                'custom_field_requests' => 10,
                'cached' => false,
            ],
        ]);

        // Batches of 5 in rank order: the second batch already holds the fifth winner.
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $fake->customFieldLookups());
    }

    #[Test]
    public function the_result_matches_filter_sort_take_five_on_random_accounts(): void
    {
        mt_srand(20261111);

        for ($round = 0; $round < 40; $round++) {
            // A fresh HTTP factory per round, so the previous round's fake stops answering.
            $this->app->forgetInstance(Factory::class);
            Http::clearResolvedInstances();
            Http::preventStrayRequests();
            config(['refersion.batch_size' => mt_rand(1, 7), 'refersion.page_limit' => mt_rand(1, 10)]);

            $fake = FakeRefersion::make();
            $eligible = [];
            for ($id = 1, $count = mt_rand(0, 35); $id <= $count; $id++) {
                $commission = mt_rand(0, 5) === 0 ? 100 : mt_rand(0, 100000) / 100; // ties now and then
                $flag = ['TRUE', 'TRUE', 'FALSE', 'true', null][mt_rand(0, 4)];
                $fake->affiliate($id, $commission, $flag);

                if ($flag === 'TRUE') {
                    $eligible[] = ['id' => $id, 'commission' => $commission];
                }
            }
            $fake->install();

            usort($eligible, fn ($a, $b) => [$b['commission'], $a['id']] <=> [$a['commission'], $b['id']]);
            $expected = array_column(array_slice($eligible, 0, 5), 'id');

            $this->assertSame($expected, array_column(app(LeaderboardService::class)->compute()['data'], 'id'), "round {$round}");
        }
    }

    #[Test]
    public function pagination_runs_from_page_one_until_a_short_page(): void
    {
        config(['refersion.page_limit' => 2]);

        foreach ([5 => [1, 2, 3], 4 => [1, 2, 3], 0 => [1]] as $count => $pages) {
            $this->app->forgetInstance(Factory::class);
            Http::clearResolvedInstances();
            Http::preventStrayRequests();

            $fake = FakeRefersion::make();
            for ($id = 1; $id <= $count; $id++) {
                $fake->affiliate($id, $id, 'TRUE');
            }
            $fake->install();

            $result = app(LeaderboardService::class)->compute();

            // A last page that is exactly full costs one extra request for an empty page.
            $this->assertSame($pages, $fake->pagesRequested(), "{$count} affiliates");
            $this->assertSame($count, $result['meta']['affiliates_scanned']);
        }
    }

    #[Test]
    public function an_api_that_ignores_page_is_caught_on_the_first_repeat(): void
    {
        config(['refersion.page_limit' => 2]);
        $samePage = fn () => Http::response(['data' => [['id' => 1], ['id' => 2]]]);
        $fake = FakeRefersion::make()->queue('list', ...array_fill(0, 5, $samePage))->install();

        $this->getJson('/api/leaderboard')->assertStatus(502)->assertJsonPath('error.code', 'upstream_bad_response');
        $this->assertSame([1, 2], $fake->pagesRequested());
    }

    #[Test]
    public function an_unreadable_commission_only_matters_if_the_affiliate_is_eligible(): void
    {
        $fake = FakeRefersion::make()->affiliate(1, '+5', 'FALSE')->affiliate(2, 10, 'TRUE')->install();

        $this->getJson('/api/leaderboard')->assertOk()
            ->assertJsonPath('data.0.id', 2)->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.affiliates_scanned', 2);

        $fake->customFields(1, [['label' => 'Display in leaderboard', 'value' => 'TRUE']]);

        $this->getJson('/api/leaderboard?fresh=1')->assertStatus(502)->assertJsonPath('error.code', 'upstream_bad_response');
    }

    #[Test]
    public function the_board_is_cached_until_a_fresh_read(): void
    {
        $fake = FakeRefersion::make()->affiliate(1, 10, 'TRUE')->install();

        $this->getJson('/api/leaderboard')->assertJsonPath('meta.cached', false);
        $this->getJson('/api/leaderboard')->assertJsonPath('meta.cached', true);
        $this->getJson('/api/leaderboard?fresh=1')->assertJsonPath('meta.cached', false);

        $this->assertSame([1, 1], $fake->pagesRequested(), 'the cached read made no requests');
        $this->assertSame(1, $fake->tokensIssued, 'the token is cached too');
    }
}
