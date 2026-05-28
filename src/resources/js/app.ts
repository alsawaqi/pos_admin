import '../css/app.css';

import { createApp } from 'vue';
import App from './App.vue';
import { enforceInitialAuthRouting } from './lib/authBoot';
import { installBfcacheGuard } from './lib/bfcacheGuard';
import { applyDocumentDirection, i18n } from './lib/i18n';
import { router } from './router';
import { initSentry } from './lib/sentry';

// Sanity-check the auth-state-to-path contract BEFORE Vue mounts. If the
// page we're about to render disagrees with the server's stamped
// __INITIAL_AUTH__ (e.g. a bfcache restore that beat every other guard),
// hard-navigate now and never let the wrong shell paint.
enforceInitialAuthRouting();

applyDocumentDirection();
installBfcacheGuard();

const app = createApp(App)
    .use(i18n)
    .use(router);

// Sprint 3 hardening — Sentry init. No-op when VITE_SENTRY_DSN
// is unset (local dev), so it's safe at every stage. Must run
// AFTER router is registered so the BrowserTracing integration
// can hook into route transitions, but BEFORE mount so the
// initial render is already inside a transaction.
initSentry(app, router);

app.mount('#app');
