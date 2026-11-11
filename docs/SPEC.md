# Refersion Affiliate Leaderboard: Spec

Laravel 12 (PHP 8.4) + React 19 / TypeScript, everything in Docker. Timebox: 90 minutes.

## 1. Goal

Show the **top five affiliates** of a Refersion account by lifetime commission
(`performance.total.commission`, descending). Only affiliates with a custom field labelled
`Display in leaderboard` whose value is the string `TRUE` count. The board is shown on a React
page and by `php artisan leaderboard:show`, both using the same service.

Out of scope: a database, user login, deployment.

## 2. Refersion API (v2026-08)

Verified against the OpenAPI embedded in [refersion.dev](https://www.refersion.dev/docs/api-reference/authentication)
and against a real account.

| | |
|---|---|
| Token | `POST https://auth.refersion.com/oauth/token`, form fields `client_id`, `client_secret`, `token_type`. `short_lived` lasts 3600 s. Requesting a `long_lived` token revokes the previous one, so the app uses `short_lived` and caches it for `expires_in − 60 s`. |
| List | `GET /affiliates?page=&limit=` (`limit` 1–100) → `{data: [...]}`, with **no pagination metadata**. Fields used: `id` (int), `firstName`, `lastName`, `company`, `performance.total.commission` (number; `performance` may be missing). |
| Custom fields | `GET /affiliates/{id}/custom-fields` → `{data: [{id, label, value (string), ...}]}`. 404 means the affiliate is gone. |
| Limits | 300 reads/min per account. A 429 carries `Retry-After` in seconds. |

## 3. Rules

- **Pagination** (from the brief): start at page 1 and request the next page until one returns
  fewer records than `limit`. An exactly full last page costs one extra request.
- **Eligibility:** `label === "Display in leaderboard"` and `value === "TRUE"`, case-sensitive
  and untrimmed. If the label appears more than once, all its values must be `TRUE`.
- **Ranking:** commission descending, then id ascending. Commissions are decimal strings
  compared with bcmath. A missing `performance` counts as 0.
- **Early stop:** check custom fields in rank order, 5 concurrent lookups at a time, and stop
  once 5 eligible affiliates are found. This gives the same result as filtering everyone.

## 4. Errors

| Situation | Response |
|---|---|
| Connection error, 5xx, 429 | Retried: 3 attempts, 200 ms then 400 ms, or `Retry-After`. Still failing → 503 `upstream_unavailable` / `upstream_rate_limited` |
| `Retry-After` > 10 s | Fails fast: 503 `upstream_rate_limited` with the same `Retry-After` |
| 401 | New token, one more try. Still 401, or 403 → 502 `upstream_auth_failed` |
| Bad credentials | 502 `upstream_auth_failed` |
| Other 4xx, no `data` list, record without an id | 502 `upstream_bad_response` |
| Unreadable commission (e.g. `"+5"`) | Its custom fields are checked: not eligible → skipped and logged; eligible → 502 `upstream_bad_response` |
| Custom-field lookup fails after retries | The whole request fails. Skipping the affiliate could publish a wrong top 5 |
| API serves the same page again (ignores `page`) | 502 on the first repeat. A `REFERSION_MAX_PAGES` cap (1000) covers any other runaway |
| Unknown route, wrong method, throttled, bug | Same shape: `not_found`, `method_not_allowed`, `too_many_requests`, `internal_error` |

Every error body is `{error: {code, message}}`. Upstream error text and exception details are
logged, never returned. The CLI prints `[code] message` and exits with 1; with `--json` it prints
the error body.

## 5. Our API

`GET /api/leaderboard[?fresh=1]`

```json
{
  "data": [{ "rank": 1, "id": 8572722, "name": "Lily Kertzmann", "commission": "702513.00" }],
  "meta": { "generated_at": "2026-11-11T14:00:00Z", "affiliates_scanned": 44, "pages_fetched": 1, "custom_field_requests": 15, "cached": false }
}
```

The name is "First Last", else the company, else `Affiliate #id`. The result is cached for
`LEADERBOARD_CACHE_TTL` seconds (300) per account. `fresh=1` recomputes it and is limited to
6/min per client; plain reads to 120/min.

## 6. Configuration

`REFERSION_BASE_URL`, `REFERSION_TOKEN_URL`, `REFERSION_CLIENT_ID`, `REFERSION_CLIENT_SECRET`,
`REFERSION_PAGE_LIMIT` (100), `REFERSION_BATCH_SIZE` (5), `REFERSION_MAX_PAGES` (1000),
`LEADERBOARD_CACHE_TTL` (300). `.env` is git-ignored; `.env.example` points at the local mock.

## 7. Environment

Nothing runs on the host. An AI agent with command execution worked on this repo, and
containers keep what it can touch down to the project directory. npm installs are pinned and
limited to versions published before a cutoff date (`.npmrc`).

## 8. Open questions

| # | Question | Current answer |
|---|---|---|
| Q5 | Should eligibility ignore case and whitespace? | No, the brief is literal. On the real account a relaxed rule changes ranks 2 and 3 |
| Q6 | Should pending, denied or banned affiliates be excluded? | No, the brief has no such condition. All 44 real affiliates are approved |
