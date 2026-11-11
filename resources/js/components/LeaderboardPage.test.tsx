import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { jsonResponse, leaderboard } from '../test/fixtures';
import { LeaderboardPage } from './LeaderboardPage';

function stubFetch(...responses: Array<() => Promise<Response>>) {
    const mock = vi.fn(async (..._args: Parameters<typeof fetch>) => (responses.shift() ?? (() => Promise.reject(new Error('Unexpected fetch'))))());
    vi.stubGlobal('fetch', mock);
    return mock;
}

describe('LeaderboardPage', () => {
    it('shows loading, then the ranked table', async () => {
        stubFetch(async () => jsonResponse(leaderboard()));

        render(<LeaderboardPage />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading leaderboard');

        const rows = within(await screen.findByRole('table')).getAllByRole('row').slice(1);
        expect(rows).toHaveLength(5);
        expect(rows[0]).toHaveTextContent('1Northwind Media800677124,629.37');
        expect(screen.getByText(/237 affiliates scanned/)).toBeInTheDocument();
    });

    it('shows our own error message and retries', async () => {
        const fetchMock = stubFetch(
            async () => jsonResponse({ error: { code: 'upstream_bad_response', message: 'GET /affiliates returned HTTP 422.' } }, 502),
            async () => jsonResponse(leaderboard()),
        );

        render(<LeaderboardPage />);

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Something went wrong');
        expect(alert).not.toHaveTextContent('HTTP 422');

        await userEvent.click(within(alert).getByRole('button', { name: 'Retry' }));
        expect(await screen.findByRole('table')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('keeps the table while refreshing, and after a failed refresh', async () => {
        const fetchMock = stubFetch(
            async () => jsonResponse(leaderboard()),
            async () => jsonResponse({ error: { code: 'too_many_requests', message: 'x' } }, 429, { 'Retry-After': '30' }),
        );

        render(<LeaderboardPage />);
        await userEvent.click(await screen.findByRole('button', { name: 'Refresh' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Refresh failed: Too many requests. Try again in 30 seconds.');
        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/leaderboard?fresh=1');
    });
});
