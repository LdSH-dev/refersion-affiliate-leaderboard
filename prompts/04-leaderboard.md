# 04 · Leaderboard

Rank the affiliates and expose the result at `GET /api/leaderboard` and `leaderboard:show`.
Custom fields cost one request each, so only look them up in rank order until we have five.

**Done when**
- Pagination follows the brief: page 1 until a short page.
- Eligibility is exactly `Display in leaderboard` = `TRUE`.
- Ties are stable. A test compares the early stop with "filter all, sort, take 5".
- Money never goes through a float.
- The result is cached, and `?fresh=1` / `--fresh` bypasses the cache.
