import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
import './echo'; // Initialize Laravel Echo for real-time updates
import * as Sentry from '@sentry/vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        // Initialize Sentry for Vue.js frontend error tracking
        Sentry.init({
            app,
            dsn: import.meta.env.VITE_SENTRY_DSN || 'https://59d8da25303031f0ec664fe407c593e3@o4509526861676544.ingest.us.sentry.io/4509526931013632',
            environment: import.meta.env.VITE_APP_ENV || 'development',
            // Setting this option to true will send default PII data to Sentry.
            // For example, automatic IP address collection on events
            sendDefaultPii: true,
            integrations: [
                Sentry.browserTracingIntegration(),
                Sentry.replayIntegration({
                    maskAllText: false,
                    blockAllMedia: false,
                }),
            ],
            tracesSampleRate: import.meta.env.VITE_APP_ENV === 'production' ? 0.1 : 1.0,
            replaysSessionSampleRate: 0.1,
            replaysOnErrorSampleRate: 1.0,
        });

        app.use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
