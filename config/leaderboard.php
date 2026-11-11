<?php

return [
    'size' => 5,
    // Seconds the computed board is cached; ?fresh=1 / --fresh bypasses it.
    'cache_ttl' => (int) env('LEADERBOARD_CACHE_TTL', 300),
];
