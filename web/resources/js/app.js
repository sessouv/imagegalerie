import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './Layouts/AppLayout.vue';

const pages = import.meta.glob('./Pages/*.vue', { eager: true });

createInertiaApp({
    title: (title) => (title ? `${title} · Timbre` : 'Timbre'),
    resolve: (name) => {
        const page = pages[`./Pages/${name}.vue`].default;
        if (page.layout === undefined) {
            page.layout = AppLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#5b3df5' },
});
