import { defineConfig } from 'vitest/config';

/**
 * Kept separate from vite.config.js so the Laravel/Tailwind build plugins
 * play no part in the test run. The completion registry is plain JavaScript
 * with no DOM, so the node environment is all it needs.
 */
export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
});
