import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import { enforceInitialAuthRouting } from './lib/authBoot';
import { installBfcacheGuard } from './lib/bfcacheGuard';
import { applyDocumentDirection, i18n } from './lib/i18n';
import { router } from './router';

// Sanity-check the auth-state-to-path contract BEFORE Vue mounts. If the
// page we're about to render disagrees with the server's stamped
// __INITIAL_AUTH__ (e.g. a bfcache restore that beat every other guard),
// hard-navigate now and never let the wrong shell paint.
enforceInitialAuthRouting();

applyDocumentDirection();
installBfcacheGuard();

createApp(App)
    .use(i18n)
    .use(router)
    .mount('#app');
