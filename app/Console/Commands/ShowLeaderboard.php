<?php

namespace App\Console\Commands;

use App\Domain\Money;
use App\Leaderboard\LeaderboardService;
use App\Refersion\RefersionException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\TableStyle;

final class ShowLeaderboard extends Command
{
    protected $signature = 'leaderboard:show
        {--fresh : Bypass the cache}
        {--json : Print the same JSON as GET /api/leaderboard}';

    protected $description = 'Show the top affiliates by lifetime commission';

    public function handle(LeaderboardService $leaderboard): int
    {
        try {
            $result = $leaderboard->get(fresh: (bool) $this->option('fresh'));
        } catch (RefersionException $e) {
            $this->option('json')
                ? $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]])
                : $this->components->error("[{$e->errorCode}] {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->json($result);

            return self::SUCCESS;
        }

        if ($result['data'] === []) {
            $this->components->warn('No eligible affiliates.');
        } else {
            $this->table(
                ['Rank', 'Affiliate', 'ID', 'Lifetime commission'],
                array_map(fn (array $e) => [$e['rank'], $this->plain($e['name']), $e['id'], Money::format($e['commission'])], $result['data']),
                columnStyles: [3 => (new TableStyle)->setPadType(STR_PAD_LEFT)],
            );
        }

        $meta = $result['meta'];
        $this->line(sprintf(
            '<fg=gray>Generated at %s (%s) · %d affiliates · %d page(s) · %d custom-field lookup(s)</>',
            $meta['generated_at'], $meta['cached'] ? 'cached' : 'fresh', $meta['affiliates_scanned'], $meta['pages_fetched'], $meta['custom_field_requests'],
        ));

        return self::SUCCESS;
    }

    private function json(array $payload): void
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Affiliates choose their own names: print them literally, without console tags or escapes. */
    private function plain(string $name): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
    }
}
