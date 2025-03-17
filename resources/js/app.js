import '../css/app.css';
import './bootstrap';
import 'vue-toast-notification/dist/theme-default.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// components
import Card from './Components/Card.vue';
import PrimaryButton from './Components/PrimaryButton.vue';
import TextInput from './Components/TextInput.vue';
import DropDownBox from './Components/DropDownBox.vue';

// icons
import FolderIcon from '@/Components/Icons/Folder.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Minizo';

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
            .component('Card', Card)
            .component('PrimaryButton', PrimaryButton)
            .component('TextInput', TextInput)
            .component('DropDownBox', DropDownBox)
            .component('FolderIcon', FolderIcon)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
