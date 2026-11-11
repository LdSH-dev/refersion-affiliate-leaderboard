# 08 · Edge cases

Look for edge cases the current tests don't cover, starting with odd data from the API. Explain
each one simply before changing anything.

Follow-up, after the agent pointed out that one unreadable commission (e.g. `"+5"`) failed the
whole board even when that affiliate wasn't eligible: only check the affiliate's custom fields
in that case. Skip it if it isn't eligible; fail if it is, since we can't know its rank.

**Done when**
- An ineligible affiliate with an unreadable commission no longer breaks the board.
- An eligible one still fails with `upstream_bad_response`.
- The real-account top 5 is unchanged and all tests pass.
