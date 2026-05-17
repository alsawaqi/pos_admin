import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import { installBfcacheGuard } from './lib/bfcacheGuard';
import { applyDocumentDirection, i18n } from './lib/i18n';
import { router } from './router';

applyDocumentDirection();
installBfcacheGuard(router);

createApp(App)
    .use(i18n)
    .use(router)
    .mount('#app');
