/** Mirrors GET /api/leaderboard (docs/SPEC.md §7). */
export interface LeaderboardEntry {
    rank: number;
    id: number;
    name: string;
    /** Decimal string, e.g. "1520.75". Never parsed into a float. */
    commission: string;
}

export interface LeaderboardMeta {
    generated_at: string;
    affiliates_scanned: number;
    pages_fetched: number;
    custom_field_requests: number;
    cached: boolean;
}

export interface LeaderboardResponse {
    data: LeaderboardEntry[];
    meta: LeaderboardMeta;
}

export type ErrorCode =
    | 'upstream_auth_failed'
    | 'upstream_rate_limited'
    | 'upstream_unavailable'
    | 'upstream_bad_response'
    | 'too_many_requests'
    | 'network_error'
    | 'unknown';

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly code: ErrorCode,
        public readonly status: number | null,
        /** Seconds, from the Retry-After header, when the server sent one. */
        public readonly retryAfter: number | null = null,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

const KNOWN_CODES: readonly ErrorCode[] = [
    'upstream_auth_failed',
    'upstream_rate_limited',
    'upstream_unavailable',
    'upstream_bad_response',
    'too_many_requests',
];

export async function fetchLeaderboard(options: { fresh?: boolean; signal?: AbortSignal } = {}): Promise<LeaderboardResponse> {
    const url = options.fresh ? '/api/leaderboard?fresh=1' : '/api/leaderboard';

    let response: Response;
    try {
        response = await fetch(url, { headers: { Accept: 'application/json' }, signal: options.signal });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }
        throw new ApiError('Could not reach the server.', 'network_error', null);
    }

    const body: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw toApiError(response, body);
    }

    if (!isLeaderboardResponse(body)) {
        throw new ApiError('The server returned an unexpected response.', 'unknown', response.status);
    }

    return body;
}

function toApiError(response: Response, body: unknown): ApiError {
    const retryAfterHeader = response.headers.get('Retry-After');
    const retryAfter = retryAfterHeader !== null && /^\d+$/.test(retryAfterHeader) ? Number(retryAfterHeader) : null;

    if (isRecord(body) && isRecord(body.error)) {
        const code = KNOWN_CODES.find((known) => known === body.error.code) ?? 'unknown';
        const message = typeof body.error.message === 'string' ? body.error.message : `Request failed (HTTP ${response.status}).`;

        return new ApiError(message, code, response.status, retryAfter);
    }

    // Anything else (e.g. Laravel's generic {message}, a proxy's HTML page): never show the
    // server's raw text to the user, it can carry internals.
    return new ApiError(`Request failed (HTTP ${response.status}).`, response.status === 429 ? 'too_many_requests' : 'unknown', response.status, retryAfter);
}

function isRecord(value: unknown): value is Record<string, any> {
    return typeof value === 'object' && value !== null;
}

function isLeaderboardEntry(value: unknown): value is LeaderboardEntry {
    return (
        isRecord(value) &&
        Number.isInteger(value.rank) &&
        Number.isInteger(value.id) &&
        typeof value.name === 'string' &&
        typeof value.commission === 'string' &&
        /^-?\d+(\.\d+)?$/.test(value.commission)
    );
}

function isLeaderboardResponse(value: unknown): value is LeaderboardResponse {
    return (
        isRecord(value) &&
        Array.isArray(value.data) &&
        value.data.every(isLeaderboardEntry) &&
        isRecord(value.meta) &&
        typeof value.meta.generated_at === 'string'
    );
}
