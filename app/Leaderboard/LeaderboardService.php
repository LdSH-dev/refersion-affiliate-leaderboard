<?php

namespace App\Leaderboard;

use App\Domain\Affiliate;
use App\Domain\EligibilityPolicy;
use App\Refersion\RefersionClient;
use App\Refersion\RefersionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Top eligible affiliates by lifetime commission (docs/SPEC.md §5).
 *
 * Every affiliate is fetched, because the top commission can be on any page. Custom
 * fields cost one request per affiliate, so they are checked in commission order, a
 * small concurrent batch at a time, until enough eligible affiliates are found. The
 * result equals "filter all, sort, take 5" with far fewer requests.
 */
final class LeaderboardService
{
    public function __construct(private readonly RefersionClient $client) {}

    /**
     * @return array{data: list<array{rank: int, id: int, name: string, commission: string}>, meta: array<string, mixed>}
     */
    public function get(bool $fresh = false): array
    {
        // One cache entry per account, so switching .env never serves another account's board.
        $key = 'leaderboard:'.sha1(config('refersion.base_url').'|'.config('refersion.client_id'));
        $cached = $fresh ? null : Cache::get($key);

        if (is_array($cached)) {
            $cached['meta']['cached'] = true;

            return $cached;
        }

        $result = $this->compute();
        Cache::put($key, $result, (int) config('leaderboard.cache_ttl'));

        return $result;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function compute(): array
    {
        [$all, $pages] = $this->allAffiliates();
        $affiliates = array_values(array_filter($all, fn (Affiliate $a) => $a->commission !== null));
        usort($affiliates, Affiliate::compare(...));

        $lookups = $this->skipUnreadable(array_filter($all, fn (Affiliate $a) => $a->commission === null));
        $size = (int) config('leaderboard.size');
        $winners = [];

        foreach (array_chunk($affiliates, max(1, (int) config('refersion.batch_size'))) as $batch) {
            $fields = $this->client->customFields(array_map(fn (Affiliate $a) => $a->id, $batch));
            $lookups += count($batch);

            foreach ($batch as $affiliate) {
                if (EligibilityPolicy::isEligible($fields[$affiliate->id]) && count($winners) < $size) {
                    $winners[] = $affiliate;
                }
            }

            if (count($winners) >= $size) {
                break;
            }
        }

        Log::info('Leaderboard computed', ['affiliates' => count($all), 'pages' => $pages, 'lookups' => $lookups]);

        return [
            'data' => array_map(fn (Affiliate $a, int $i) => [
                'rank' => $i + 1,
                'id' => $a->id,
                'name' => $a->name,
                'commission' => $a->commission,
            ], $winners, array_keys($winners)),
            'meta' => [
                'generated_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'affiliates_scanned' => count($all),
                'pages_fetched' => $pages,
                'custom_field_requests' => $lookups,
                'cached' => false,
            ],
        ];
    }

    /**
     * An affiliate whose commission can't be read can't be ranked. If it isn't eligible it
     * doesn't matter, so it is skipped; if it is, failing beats publishing a wrong top 5.
     *
     * @param  array<Affiliate>  $unreadable
     * @return int custom-field lookups made
     */
    private function skipUnreadable(array $unreadable): int
    {
        if ($unreadable === []) {
            return 0;
        }

        $ids = array_map(fn (Affiliate $a) => $a->id, array_values($unreadable));

        foreach (array_chunk($ids, max(1, (int) config('refersion.batch_size'))) as $batch) {
            foreach ($this->client->customFields($batch) as $id => $fields) {
                if (EligibilityPolicy::isEligible($fields)) {
                    throw RefersionException::badResponse("Affiliate {$id} is eligible but its lifetime commission is unreadable.");
                }

                Log::warning('Skipping an ineligible affiliate with an unreadable commission', ['affiliate_id' => $id]);
            }
        }

        return count($ids);
    }

    /**
     * The brief's pagination rule: from page 1, until a page has fewer records than the limit.
     *
     * @return array{0: list<Affiliate>, 1: int} affiliates (first occurrence of each id) and pages fetched
     */
    private function allAffiliates(): array
    {
        $limit = max(1, min(100, (int) config('refersion.page_limit')));
        $maxPages = max(1, (int) config('refersion.max_pages'));
        $affiliates = [];
        $previousIds = null;

        for ($page = 1; ; $page++) {
            $records = $this->client->affiliates($page, $limit);
            $ids = array_map(fn (mixed $r) => is_array($r) ? ($r['id'] ?? null) : null, $records);

            // An API that ignores ?page would serve the same full page forever.
            if ($records !== [] && $ids === $previousIds) {
                throw RefersionException::badResponse("GET /affiliates returned the same records for pages {$page} and ".($page - 1).'.');
            }

            // Past the cap, only an empty page proves the account ended exactly on the cap.
            if ($page > $maxPages && $records !== []) {
                throw RefersionException::badResponse("GET /affiliates still returned records after {$maxPages} pages.");
            }

            foreach ($records as $record) {
                $affiliate = Affiliate::fromPayload($record);
                // Records can shift between pages while paginating; keep the first copy.
                $affiliates[$affiliate->id] ??= $affiliate;
            }

            if (count($records) < $limit) {
                return [array_values($affiliates), $page];
            }

            $previousIds = $ids;
        }
    }
}
