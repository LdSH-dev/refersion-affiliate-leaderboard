import type { LeaderboardResponse } from '../api';

export function leaderboard(overrides: Partial<LeaderboardResponse> = {}): LeaderboardResponse {
    return {
        data: [
            { rank: 1, id: 8006771, name: 'Northwind Media', commission: '24629.37' },
            { rank: 2, id: 8006290, name: 'Lara Dubois', commission: '23695.94' },
            { rank: 3, id: 8006586, name: 'Elena Garcia', commission: '23695.94' },
            { rank: 4, id: 8006919, name: 'Farah Garcia', commission: '23466.81' },
            { rank: 5, id: 8001221, name: 'Rafael Almeida', commission: '23012.41' },
        ],
        meta: {
            generated_at: '2026-11-11T14:00:00Z',
            affiliates_scanned: 237,
            pages_fetched: 3,
            custom_field_requests: 15,
            cached: false,
        },
        ...overrides,
    };
}

export function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', ...headers },
    });
}
