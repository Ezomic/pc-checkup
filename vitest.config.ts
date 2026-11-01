import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/__tests__/**/*.test.ts'],
        // jsdom everywhere rather than per-file annotations: the cost on a pure
        // composable spec is negligible, and a component spec that forgets the
        // annotation fails with a confusing "document is not defined".
        environment: 'jsdom',
    },
});
