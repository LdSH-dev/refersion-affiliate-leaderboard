# 03 · Refersion client

A client for the two endpoints we need: get and cache a token, list affiliates, read custom
fields. Keep it small.

**Done when**
- Connection errors, 429 and 5xx are retried a couple of times; a long `Retry-After` fails fast.
- A 401 gets a new token once.
- Every failure becomes one of four error codes, and no secret ends up in a response or log.
- Tests use faked HTTP only.
