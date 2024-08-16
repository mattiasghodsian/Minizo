import './assets/style.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';

import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/lara';
import ToastService from 'primevue/toastservice';

import App from './App.vue';
import router from './router';

const app = createApp(App);

import InputText from 'primevue/inputtext';
import ToggleSwitch from 'primevue/toggleswitch';
import Select from 'primevue/select';
import Button from 'primevue/button';
import Toast from 'primevue/toast';
import Dialog from 'primevue/dialog';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';

app.use(createPinia());
app.use(router);
app.use(PrimeVue, {
    theme: {
        preset: Aura,  
        options: {
            prefix: 'p',
            darkModeSelector: '',
        }  
    }
});
app.use(ToastService);
app.component('InputText', InputText);
app.component('Dialog', Dialog);
app.component('ToggleSwitch', ToggleSwitch);
app.component('Select', Select);
app.component('Button', Button);
app.component('Toast', Toast);
app.component('DataTable', DataTable);
app.component('Column', Column);

app.mount('#app');