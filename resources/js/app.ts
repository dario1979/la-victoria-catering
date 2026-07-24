import { createPinia } from 'pinia';
import { createApp } from 'vue';
import { registerSW } from 'virtual:pwa-register';
import App from './components/App.vue';

createApp(App).use(createPinia()).mount('#app');
registerSW({ immediate: true });
