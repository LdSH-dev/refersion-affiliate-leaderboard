import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { LeaderboardPage } from './components/LeaderboardPage';

const root = document.getElementById('app');

if (root) {
    createRoot(root).render(
        <StrictMode>
            <LeaderboardPage />
        </StrictMode>,
    );
}
