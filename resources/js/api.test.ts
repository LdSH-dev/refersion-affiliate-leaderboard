import { describe, expect, it, vi } from 'vitest';
import { fetchLeaderboard } from './api';
import { jsonResponse, leaderboard } from './test/fixtures';

const stubFetch = (response: () => Promise<Response>) => vi.stubGlobal('fetch', vi.fn(response));

describe('fetchLeaderboard', () => {
    it('returns the payload and maps our error shape', async () => {
        stubFetch(async () => jsonResponse(leaderboard()));
        await expect(fetchLeaderboard()).resolves.toEqual(leaderboard());

        stubFetch(async () => jsonResponse({ error: { code: 'upstream_rate_limited', message: 'x' } }, 503, { 'Retry-After': '42' }));
        await expect(fetchLeaderboard()).rejects.toMatchObject({ code: 'upstream_rate_limited', retryAfter: 42 });
    });

    it('rejects off-contract data and never passes on raw server text', async () => {
        stubFetch(async () => jsonResponse(leaderboard({ data: [{ rank: 1, id: 1, name: 'X', commission: 12 as unknown as string }] })));
        await expect(fetchLeaderboard()).rejects.toMatchObject({ code: 'unknown' });

        stubFetch(async () => jsonResponse({ message: 'SQLSTATE secret' }, 500));
        await expect(fetchLeaderboard()).rejects.toMatchObject({ code: 'unknown', message: 'Request failed (HTTP 500).' });
    });
});
