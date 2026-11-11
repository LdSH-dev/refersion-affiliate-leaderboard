import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, fetchLeaderboard, type LeaderboardResponse } from '../api';
import { formatGeneratedAt } from '../format';
import { LeaderboardTable } from './LeaderboardTable';

const LEADERBOARD_SIZE = 5;

type State =
    | { status: 'loading' }
    | { status: 'error'; error: ApiError }
    | { status: 'success'; data: LeaderboardResponse; refreshing: boolean; refreshError: ApiError | null };

export function LeaderboardPage() {
    const [state, setState] = useState<State>({ status: 'loading' });
    const controller = useRef<AbortController | null>(null);

    const load = useCallback(async (fresh: boolean) => {
        controller.current?.abort();
        const current = new AbortController();
        controller.current = current;

        setState((previous) =>
            previous.status === 'success' ? { ...previous, refreshing: true, refreshError: null } : { status: 'loading' },
        );

        try {
            const data = await fetchLeaderboard({ fresh, signal: current.signal });
            setState({ status: 'success', data, refreshing: false, refreshError: null });
        } catch (error) {
            if (current.signal.aborted) {
                return;
            }

            const apiError = error instanceof ApiError ? error : new ApiError('Something went wrong.', 'unknown', null);

            // A failed refresh keeps the last good data on screen.
            setState((previous) =>
                previous.status === 'success'
                    ? { ...previous, refreshing: false, refreshError: apiError }
                    : { status: 'error', error: apiError },
            );
        }
    }, []);

    useEffect(() => {
        void load(false);

        return () => controller.current?.abort();
    }, [load]);

    return (
        <main className="page" aria-busy={state.status === 'loading' || (state.status === 'success' && state.refreshing)}>
            <header className="page-header">
                <h1>Affiliate Leaderboard</h1>
                <p className="subtitle">Top {LEADERBOARD_SIZE} affiliates by lifetime commission</p>
            </header>

            <section className="card">
                {state.status === 'loading' && <LoadingState />}

                {state.status === 'error' && <ErrorState error={state.error} onRetry={() => void load(false)} />}

                {state.status === 'success' && (
                    <>
                        {state.refreshError && (
                            <p className="inline-error" role="alert">
                                Refresh failed: {describeError(state.refreshError)}
                            </p>
                        )}

                        {state.data.data.length === 0 ? (
                            <EmptyState />
                        ) : (
                            <>
                                <LeaderboardTable entries={state.data.data} />
                                {state.data.data.length < LEADERBOARD_SIZE && (
                                    <p className="note">
                                        Only {state.data.data.length} eligible affiliate{state.data.data.length === 1 ? '' : 's'} found.
                                    </p>
                                )}
                            </>
                        )}

                        <footer className="card-footer">
                            <p className="meta">
                                Generated at <time dateTime={state.data.meta.generated_at}>{formatGeneratedAt(state.data.meta.generated_at)}</time>
                                <span className={`badge ${state.data.meta.cached ? 'badge-cached' : 'badge-live'}`}>
                                    {state.data.meta.cached ? 'cached' : 'live'}
                                </span>
                                <span className="muted">
                                    {' '}
                                    · {state.data.meta.affiliates_scanned} affiliates scanned · {state.data.meta.custom_field_requests} custom-field
                                    lookups
                                </span>
                            </p>
                            <button type="button" className="button" onClick={() => void load(true)} disabled={state.refreshing}>
                                {state.refreshing ? 'Refreshing…' : 'Refresh'}
                            </button>
                        </footer>
                    </>
                )}
            </section>
        </main>
    );
}

function LoadingState() {
    return (
        <div className="state" role="status">
            <span className="spinner" aria-hidden="true" />
            <span>Loading leaderboard…</span>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="state">
            <p className="state-title">No eligible affiliates</p>
            <p className="muted">
                Affiliates appear here when their <q>Display in leaderboard</q> custom field is set to <code>TRUE</code>.
            </p>
        </div>
    );
}

function ErrorState({ error, onRetry }: { error: ApiError; onRetry: () => void }) {
    return (
        <div className="state" role="alert">
            <p className="state-title">Couldn’t load the leaderboard</p>
            <p className="muted">{describeError(error)}</p>
            <button type="button" className="button" onClick={onRetry}>
                Retry
            </button>
        </div>
    );
}

/** Our own wording per error code: the server's text is never shown to the user. */
export function describeError(error: ApiError): string {
    const wait = error.retryAfter !== null ? ` Try again in ${error.retryAfter} second${error.retryAfter === 1 ? '' : 's'}.` : ' Try again shortly.';

    switch (error.code) {
        case 'upstream_auth_failed':
            return 'Refersion rejected the app’s credentials.';
        case 'upstream_rate_limited':
            return `Refersion is rate limiting requests.${wait}`;
        case 'too_many_requests':
            return `Too many requests.${wait}`;
        case 'upstream_unavailable':
            return 'Refersion is temporarily unavailable. Try again shortly.';
        case 'network_error':
            return 'Could not reach the server. Check your connection.';
        default:
            return 'Something went wrong. Try again shortly.';
    }
}
