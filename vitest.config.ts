import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.ts so tests don't load the Laravel plugin.
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/js/test/setup.ts'],
        restoreMocks: true,
        unstubGlobals: true,
    },
});
