import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import { applyDocumentDirection, i18n } from './lib/i18n';
import { router } from './router';

applyDocumentDirection();

createApp(App)
    .use(i18n)
    .use(router)
    .mount('#app');
