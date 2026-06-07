<script setup lang="ts">
/**
 * Admin Audit Log Viewer — Sprint 1.5, blueprint §4.7.
 *
 * Single-page UI that surfaces the platform-wide pos_audit_logs
 * table. Every audit-able action since Sprint 0 has been writing
 * rows here; this page is where staff finally get to read them.
 *
 * Anatomy:
 *   - Header: title + subtitle + Export CSV button (CSV exports the
 *     same filter combination as the visible table — handled
 *     server-side; client just hits the same URL with the same
 *     query string).
 *   - Filter strip: actor (text id), event substring, target type
 *     dropdown, company dropdown (loaded on mount), date range.
 *   - Table: timestamp, event chip, actor, target, scope, IP.
 *     Each row is clickable — clicking opens a side drawer with the
 *     before/after JSON diff and the metadata blob.
 *   - Pagination footer mirrors every other admin list page.
 *
 * Permission: gated by AuditLogsView (sidebar already hides the
 * nav entry when the user lacks it; this is the visible-page
 * fallback). Server-side enforces the same check.
 */

import { ShieldCheck, Search, Download, X as IconClose } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    buildAuditLogExportUrl,
    listAuditLogs,
    type AuditLogEntry,
    type AuditLogTargetType,
    type AuditLogsQuery,
} from '@/lib/api/auditLogs';
import { listMerchants, type MerchantListItem, type PaginationMeta } from '@/lib/api/merchants';

const { t, locale } = useI18n();

// ---- Table state ---------------------------------------------------
const entries = ref<AuditLogEntry[]>([]);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

// ---- Filter state --------------------------------------------------
// Each filter is its own ref so the watcher debounce can collapse
// rapid typing in a single field without triggering on unrelated
// state churn.
const actorIdInput = ref(''); // raw string so the input stays controlled
const eventTerm = ref('');
const targetType = ref<AuditLogTargetType | ''>('');
const companyUuid = ref('');
const fromDate = ref('');
const toDate = ref('');
const page = ref(1);

// Companies for the dropdown — loaded once on mount. If load fails
// we let the filter stay empty (the dropdown still works for "all
// companies").
const merchants = ref<MerchantListItem[]>([]);

// ---- Detail drawer state ------------------------------------------
const selected = ref<AuditLogEntry | null>(null);

// Static dropdown options. Mirror the server-side TARGET_TYPE_MAP +
// snake_case-basename fallbacks (see AuditLogResource). Adding a new
// entry on the server should mirror to here so it appears in the
// dropdown.
const targetTypeOptions: AuditLogTargetType[] = [
    'company',
    'branch',
    'device',
    'user',
    'company_document',
    'business_activity',
    'device_make',
    'device_model',
];

/**
 * Build the query the API client will pass. Empty strings collapse
 * to `undefined` so they're stripped from the URL — matches the
 * convention used by every other admin list page.
 */
function currentQuery(): AuditLogsQuery {
    const q: AuditLogsQuery = {
        page: page.value,
    };

    // actor_id is a raw string in the input — parse to number only
    // when non-empty + actually numeric so a typo doesn't 422.
    if (actorIdInput.value.trim() !== '') {
        const parsed = Number.parseInt(actorIdInput.value, 10);
        if (Number.isFinite(parsed) && parsed > 0) {
            q.actor_id = parsed;
        }
    }

    if (eventTerm.value.trim() !== '') {
        q.event = eventTerm.value.trim();
    }

    if (targetType.value !== '') {
        q.target_type = targetType.value;
    }

    if (companyUuid.value !== '') {
        q.company_uuid = companyUuid.value;
    }

    if (fromDate.value !== '') {
        q.from = fromDate.value;
    }

    if (toDate.value !== '') {
        q.to = toDate.value;
    }

    return q;
}

async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listAuditLogs(currentQuery());
        entries.value = response.data;
        meta.value = response.meta;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load audit log';
    } finally {
        loading.value = false;
    }
}

async function loadMerchants(): Promise<void> {
    try {
        // Pull the maximum batch — 100 is more than enough for pilot
        // scale. If the platform ever grows past that, we'd swap the
        // filter to an async-search Combobox.
        const response = await listMerchants({ per_page: 100 });
        merchants.value = response.data;
    } catch {
        merchants.value = [];
    }
}

// 250 ms debounce on text fields so typing doesn't fire a request
// per keystroke. Selects + dates change discretely so they could
// fire immediately, but a single timer keeps the logic uniform.
let debounceTimer: number | null = null;
watch(
    [actorIdInput, eventTerm, targetType, companyUuid, fromDate, toDate],
    () => {
        if (debounceTimer) {
            window.clearTimeout(debounceTimer);
        }
        debounceTimer = window.setTimeout(() => {
            // Any filter change resets to page 1 — the previous page
            // index may no longer exist after the result set shrinks.
            page.value = 1;
            void fetchPage();
        }, 250);
    },
);

onMounted(() => {
    void fetchPage();
    void loadMerchants();
});

// ---- Helpers used in the template ---------------------------------

/**
 * Formats the ISO timestamp the server returns into a locale-aware
 * "YYYY-MM-DD HH:MM:SS" string for the table cell. Uses Intl with
 * a fallback so a malformed input doesn't crash the row.
 */
function formatTimestamp(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    try {
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return iso;
        }
        return date.toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch {
        return iso;
    }
}

/**
 * Renders the company name in the active locale. Falls back to the
 * English name when the Arabic field is empty — keeps the column
 * meaningful for merchants without an Arabic name on file.
 */
function companyLabel(entry: AuditLogEntry): string {
    const company = entry.company;
    if (!company) {
        return '—';
    }

    if (locale.value === 'ar' && company.name_ar) {
        return company.name_ar;
    }

    return company.name;
}

/**
 * Wraps the event string for the chip. The dot-separated namespaces
 * (device.assigned, portal_user.invited) become readable labels via
 * the i18n catalogue when a translation exists, falling back to the
 * raw event string so brand-new events still render.
 */
function eventLabel(event: string): string {
    const key = `audit_log.events.${event}`;
    const translated = t(key);
    return translated === key ? event : translated;
}

/**
 * Target label combines the short type + numeric id when the row
 * has an auditable. Empty rows (event with no target) render as "—".
 */
function targetLabel(entry: AuditLogEntry): string {
    if (!entry.target_type || entry.target_id === null) {
        return '—';
    }

    const typeKey = `audit_log.target_types.${entry.target_type}`;
    const typeName = t(typeKey);
    const resolvedType = typeName === typeKey ? entry.target_type : typeName;
    return `${resolvedType} #${entry.target_id}`;
}

// Pretty-prints the JSON blobs in the drawer. JSON.stringify with
// indent=2 is fine here — payloads are small (<1 KB usually).
function pretty(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }
    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

// Drives the "Export CSV" link. Re-computed any time a filter or
// page changes so the href always reflects the visible state.
const exportUrl = computed(() => buildAuditLogExportUrl(currentQuery()));

function openDetail(entry: AuditLogEntry): void {
    selected.value = entry;
}
function closeDetail(): void {
    selected.value = null;
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header strip: section eyebrow + title + Export CSV. -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('audit_log.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('audit_log.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('audit_log.subtitle') }}
                    </p>
                </div>

                <!-- Export CSV. Plain <a> with target=_blank so the
                     browser handles the file download via the
                     server's Content-Disposition header. Same query
                     params as the table — guaranteed parity. -->
                <a
                    :href="exportUrl"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                >
                    <Download class="size-4" />
                    {{ t('audit_log.export_csv') }}
                </a>
            </div>

            <!-- Filter strip. Six inputs in a responsive grid; the
                 layout collapses to 1 column on mobile and 2 / 3
                 columns at successive breakpoints. -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <label class="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                    <Search class="size-5 shrink-0" />
                    <input
                        v-model="eventTerm"
                        type="search"
                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                        :placeholder="t('audit_log.filter.event_placeholder')"
                    >
                </label>

                <select
                    v-model="targetType"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('audit_log.filter.all_target_types') }}</option>
                    <option v-for="opt in targetTypeOptions" :key="opt" :value="opt">
                        {{ t(`audit_log.target_types.${opt}`) }}
                    </option>
                </select>

                <select
                    v-model="companyUuid"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('audit_log.filter.all_companies') }}</option>
                    <option v-for="m in merchants" :key="m.uuid" :value="m.uuid">{{ m.name }}</option>
                </select>

                <input
                    v-model="actorIdInput"
                    type="text"
                    inputmode="numeric"
                    :placeholder="t('audit_log.filter.actor_placeholder')"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >

                <!-- Date pickers — native HTML date input is fine for
                     an admin tool, no need for a JS date library. -->
                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm">
                    <span class="text-xs uppercase tracking-wide text-slate-400">{{ t('audit_log.filter.from') }}</span>
                    <input v-model="fromDate" type="date" class="flex-1 bg-transparent outline-none">
                </label>
                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm">
                    <span class="text-xs uppercase tracking-wide text-slate-400">{{ t('audit_log.filter.to') }}</span>
                    <input v-model="toDate" type="date" class="flex-1 bg-transparent outline-none">
                </label>
            </div>

            <div
                v-if="error"
                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
            >
                {{ error }}
            </div>

            <!-- Data table. Same three-state shape as Devices/Index.vue:
                 loading skeleton, empty state, or rows. -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="entries.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <ShieldCheck class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('audit_log.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.timestamp') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.event') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.actor') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.target') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.scope') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.ip') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr
                                v-for="entry in entries"
                                :key="entry.id"
                                class="cursor-pointer transition hover:bg-slate-50"
                                @click="openDetail(entry)"
                            >
                                <td class="px-5 py-4 text-xs font-mono text-slate-600">{{ formatTimestamp(entry.occurred_at) }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex items-center rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-800">
                                        {{ eventLabel(entry.event) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    <template v-if="entry.actor">
                                        <span class="block font-medium text-slate-800">{{ entry.actor.name }}</span>
                                        <span class="block text-xs text-slate-500">{{ entry.actor.email }}</span>
                                    </template>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ targetLabel(entry) }}</td>
                                <td class="px-5 py-4 text-sm text-slate-600">
                                    <template v-if="entry.company">
                                        <span class="block">{{ companyLabel(entry) }}</span>
                                        <span v-if="entry.branch" class="block text-xs text-slate-400">{{ entry.branch.name }}</span>
                                    </template>
                                    <span v-else class="text-slate-400">{{ t('audit_log.platform_scope') }}</span>
                                </td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ entry.ip_address ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="meta && meta.last_page > 1"
                    class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/60 px-5 py-3 text-sm text-slate-600"
                >
                    <span>{{ t('common.pagination_summary', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</span>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                            :disabled="page <= 1"
                            @click="page--; fetchPage()"
                        >
                            {{ t('common.previous') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                            :disabled="page >= meta.last_page"
                            @click="page++; fetchPage()"
                        >
                            {{ t('common.next') }}
                        </button>
                    </div>
                </div>
            </section>
        </section>

        <!-- Slide-over diff drawer. Renders on top of the table when
             `selected` is non-null. Click outside or the close button
             dismisses it. Uses position: fixed + inset-y-0 so it
             always reaches edge-to-edge regardless of page scroll. -->
        <div v-if="selected" class="fixed inset-0 z-50 flex">
            <!-- Backdrop. Click anywhere outside the drawer dismisses. -->
            <div class="fixed inset-0 bg-slate-950/40 backdrop-blur-sm" @click="closeDetail" />

            <!-- Drawer panel anchored to the end of the row so RTL
                 swaps it automatically to the start side. -->
            <aside
                class="relative ms-auto flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-white shadow-2xl"
                @click.stop
            >
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-50 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            {{ t('audit_log.detail.heading') }}
                        </p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">
                            {{ eventLabel(selected.event) }}
                        </h2>
                        <p class="mt-1 text-xs font-mono text-slate-500">
                            {{ formatTimestamp(selected.occurred_at) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-slate-500 transition hover:bg-slate-200/60 hover:text-slate-700"
                        :aria-label="t('audit_log.detail.close')"
                        @click="closeDetail"
                    >
                        <IconClose class="size-5" />
                    </button>
                </header>

                <div class="space-y-6 px-6 py-6">
                    <!-- Summary block: actor + target + scope + IP /
                         UA. A two-column dl works nicely for these
                         small key/value pairs. -->
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.actor') }}</dt>
                            <dd class="mt-1 text-slate-800">
                                <template v-if="selected.actor">
                                    {{ selected.actor.name }}
                                    <span class="block text-xs text-slate-500">{{ selected.actor.email }}</span>
                                </template>
                                <template v-else>—</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.target') }}</dt>
                            <dd class="mt-1 text-slate-800">{{ targetLabel(selected) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.scope') }}</dt>
                            <dd class="mt-1 text-slate-800">
                                <template v-if="selected.company">
                                    {{ companyLabel(selected) }}
                                    <span v-if="selected.branch" class="block text-xs text-slate-500">{{ selected.branch.name }}</span>
                                </template>
                                <template v-else>{{ t('audit_log.platform_scope') }}</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.table.ip') }}</dt>
                            <dd class="mt-1 text-slate-800 font-mono text-xs">{{ selected.ip_address ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.detail.user_agent') }}</dt>
                            <dd class="mt-1 break-all text-xs font-mono text-slate-700">{{ selected.user_agent ?? '—' }}</dd>
                        </div>
                    </dl>

                    <!-- Before / After diff. Side-by-side on wide
                         viewports; stacked on narrow. Pre/code keeps
                         the JSON formatting from the server. -->
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.detail.before') }}</h3>
                            <pre class="mt-2 max-h-80 overflow-auto rounded-lg border border-slate-200 bg-slate-950/95 p-3 text-xs leading-relaxed text-emerald-100"><code>{{ pretty(selected.old_values) }}</code></pre>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.detail.after') }}</h3>
                            <pre class="mt-2 max-h-80 overflow-auto rounded-lg border border-slate-200 bg-slate-950/95 p-3 text-xs leading-relaxed text-emerald-100"><code>{{ pretty(selected.new_values) }}</code></pre>
                        </div>
                    </div>

                    <div v-if="selected.metadata">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('audit_log.detail.metadata') }}</h3>
                        <pre class="mt-2 max-h-60 overflow-auto rounded-lg border border-slate-200 bg-slate-950/95 p-3 text-xs leading-relaxed text-emerald-100"><code>{{ pretty(selected.metadata) }}</code></pre>
                    </div>
                </div>
            </aside>
        </div>
    </AdminLayout>
</template>
