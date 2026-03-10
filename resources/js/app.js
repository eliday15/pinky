import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// Global Inertia error handling — prevents white screens on non-Inertia responses.
// When the server returns a non-Inertia response (e.g., raw HTML 419 error page),
// Inertia fires the 'invalid' event. Without this handler, Inertia shows the HTML
// in a modal or produces a white screen.
router.on('invalid', (event) => {
    const status = event.detail.response?.status;

    // Prevent the default behavior (modal with raw HTML)
    event.preventDefault();

    if (status === 419) {
        // CSRF token mismatch — silently reload to get fresh tokens
        router.reload();
    } else if (status === 401 || status === 403) {
        // Authentication/authorization failure — redirect to login
        window.location.href = '/login';
    } else {
        // Other non-Inertia responses — reload the current page
        router.reload();
    }
});
