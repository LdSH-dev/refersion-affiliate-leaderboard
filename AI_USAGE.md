# AI usage

| | |
|---|---|
| Harness | Claude Code (CLI) |
| Model | Claude Opus 5.5 (`claude-opus-5-5`) |
| Effort | High |
| Prompts | [`prompts/`](prompts/) |

## How it was used

- **Spec and API research.** It drafted the spec, then checked the open questions against the
  OpenAPI embedded in refersion.dev (prompt 01).
- **Code and tests.** It wrote both, running the suites in containers as it went (02–06).
- **Two independent reviews.** Separate agents with no prior context reviewed the code (07),
  then graded it like a hiring team and wrote tests to break it. Each finding was checked
  against the code before it was fixed.
- **Simplification.** The first version was too big for the brief: a custom HTTP retry layer,
  token and computation locks, two throttle tiers, a chaos mode in the mock, about 250 tests.
  It was cut down to Laravel's built-in retries and a much smaller test suite, with the same
  real-account result.
- **Checks beyond the tests.** Brute-force scripts that read every affiliate's custom fields
  on the mock and on the real account (same top 5). Headless-browser screenshots. Fresh-clone
  runs of `make setup` and the tests. Repeated `make up` / `make down` cycles.

Everything ran in Docker. On the host, only git (local, no remote) and python3 for text
edits ran. The real credentials live only in the git-ignored `.env`.

## What the AI got wrong, and how it was caught

| Issue | Caught by |
|---|---|
| Early research used third-party pages about the old `/v2` API | Cross-checking with Refersion's own docs |
| Assumed `npm view --before` respects the date, so the install failed | The install itself |
| The mock's random failures never fired (`mt_rand` was reseeded) | A chaos run that logged 0 retries |
| `make up` returned before the server listened; a stale Vite `public/hot` broke the page after `make dev` | Fresh-clone and `dev → down → up` checks |
| An API that ignores `page` was only caught after 1000 requests; an unreadable commission ranked as 0; raw upstream text reached users | The grading review's tests |
| Over-engineering for a 90-minute brief | The grading review, then a simplification pass |
