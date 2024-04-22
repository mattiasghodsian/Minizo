import './assets/style.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';

import App from './App.vue';
import router from './router';

const app = createApp(App);

app.use(createPinia());
app.use(router);

// global components
import BaseButton from './components/forms/BaseButton.vue';
import DropDown from './components/forms/DropDown.vue';
import ToggleButton from './components/forms/ToggleButton.vue';
import TextInput from '@/components/forms/TextInput.vue';

app.component('BaseButton', BaseButton);
app.component('DropDown', DropDown);
app.component('ToggleButton', ToggleButton);
app.component('TextInput', TextInput);

app.mount('#app');
