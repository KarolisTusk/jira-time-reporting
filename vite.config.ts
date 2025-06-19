import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';
import { sentryVitePlugin } from '@sentry/vite-plugin';

export default defineConfig({
    server: {
        host: 'localhost', // Force IPv4 instead of IPv6
        port: 5173,
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        // Ensure proper CORS for Laravel integration
        cors: true,
    },
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
            // Explicitly set the hot file path
            hotFile: 'public/hot',
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Sentry source maps upload (only in production builds with auth token)
        ...(process.env.SENTRY_AUTH_TOKEN ? [sentryVitePlugin({
            org: process.env.SENTRY_ORG,
            project: process.env.SENTRY_PROJECT,
            authToken: process.env.SENTRY_AUTH_TOKEN,
            sourcemaps: {
                assets: ['./public/build/**'],
                ignore: ['node_modules'],
                filesToDeleteAfterUpload: ['./public/build/**/*.map'],
            },
            disable: process.env.NODE_ENV !== 'production',
        })] : []),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    // Add build configuration for better development experience and fix chunk size
    build: {
        sourcemap: true, // Enable source maps for Sentry
        chunkSizeWarningLimit: 1000, // Increase limit to 1000KB
        rollupOptions: {
            output: {
                // Split large dependencies into separate chunks
                manualChunks: {
                    'vue-vendor': ['vue', '@inertiajs/vue3'],
                    'ui-vendor': ['reka-ui', 'lucide-vue-next'],
                    'chart-vendor': ['chart.js', 'vue-chartjs'],
                    'utils': ['clsx', 'tailwind-merge', '@vueuse/core'],
                },
            },
        },
    },
});
