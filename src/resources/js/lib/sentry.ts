/**
 * Sentry initialisation for the Admin SPA (Sprint 3 hardening).
 *
 * Kept in its own file (rather than inlined in app.ts) so that:
 *   - the init logic stays testable,
 *   - bundlers can tree-shake the Sentry SDK out entirely when
 *     VITE_SENTRY_DSN is empty at build time,
 *   - the auth.ts store (and any future error-boundary code) can
 *     import a single tiny `setSentryUser` helper without pulling
 *     the entire SDK along.
 *
 * Behaviour:
 *   - No DSN → init() short-circuits, helpers become no-ops.
 *     Local dev runs without sending anything; staging/prod with
 *     a DSN actually reports.
 *   - Router integration is wired so route transitions get
 *     captured as performance spans, and uncaught errors carry
 *     the route name for triage.
 *   - traceparent header is propagated automatically by the
 *     BrowserTracing integration so a Vue-side error chain can be
 *     joined to its Laravel-side trace in Sentry's waterfall.
 */

import * as Sentry from '@sentry/vue';
import type { App } from 'vue';
import type { Router } from 'vue-router';

/**
 * Sets up Sentry on a Vue app + router. Safe to call when no DSN
 * is configured — becomes a complete no-op.
 */
export function initSentry(app: App, router: Router): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN as string | undefined;
    if (!dsn) {
        // Empty/undefined DSN → don't even register the SDK.
        // Local dev path; also the only path during build when the
        // env var is intentionally unset.
        return;
    }

    Sentry.init({
        app,
        dsn,
        environment: (import.meta.env.VITE_SENTRY_ENVIRONMENT as string) || 'local',
        // Vue-specific options: none needed on @sentry/vue 10 — template
        // errors are always captured (the v7-era logErrors flag is gone),
        // and per-component performance tracking (now vueIntegration's
        // tracingOptions) stays off: not worth the overhead for an admin
        // SPA with ~20 pages.

        // Tracing — route transitions become spans, fetch/XHR
        // calls automatically propagate the W3C traceparent
        // header so the Laravel side stitches into the same trace
        // when its Sentry SDK is also configured.
        integrations: [
            Sentry.browserTracingIntegration({ router }),
        ],
        tracesSampleRate: parseFloat(
            (import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE as string) || '0.1',
        ),

        // Don't ship the request URL's query string by default —
        // it can contain merchant uuids that staff treat as
        // semi-sensitive. Sentry's default sanitiser drops obvious
        // secrets but UUIDs slip through.
        beforeSend(event) {
            if (event.request?.url) {
                try {
                    const u = new URL(event.request.url);
                    u.search = '';
                    event.request.url = u.toString();
                } catch {
                    // URL parse failed (rare) — leave as-is.
                }
            }
            return event;
        },
    });
}

/**
 * Sets / clears the Sentry user context. Call from the auth store
 * on login + logout. Becomes a no-op when Sentry isn't initialised
 * because Sentry's hub is a NullHub in that case.
 */
export function setSentryUser(user: { id?: number | string; email?: string } | null): void {
    if (user) {
        Sentry.setUser({
            id: user.id !== undefined ? String(user.id) : undefined,
            email: user.email,
        });
    } else {
        Sentry.setUser(null);
    }
}
