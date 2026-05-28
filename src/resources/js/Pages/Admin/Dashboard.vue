<script setup lang="ts">
/**
 * Admin landing page (blueprint §4.8 — Slim Sprint 2).
 *
 * Single fetch to /admin/api/v1/dashboard/summary on mount. Every
 * tile, chart, table, and feed below renders from that one response.
 *
 * Scope deliberately narrowed from the full §4.8 spec because data
 * for the POS-app and scalefusion KPIs (sales, round-up, top
 * merchants, reconciliation queue, device alerts) doesn't exist
 * yet — those tiles will come back when the respective producers
 * are built. See [scalefusion-naming memory] + Sprint 8+ for
 * timeline.
 */

import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import {
    Building2,
    ClipboardList,
    History,
    MonitorSmartphone,
    Plus,
    ShieldCheck,
} from 'lucide-vue-next';

import AdminLayout from '@/Layouts/AdminLayout.vue';
import DonutChart from '@/Components/Admin/DonutChart.vue';
import MetricCard from '@/Components/Admin/MetricCard.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { getDashboardSummary, type DashboardSummary } from '@/lib/api/dashboard';
import type { CompanyStatus } from '@/lib/api/merchants';
import type { DeviceStatus } from '@/lib/api/devices';
import { PlatformPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();

const summary = ref<DashboardSummary | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await getDashboardSummary();
        summary.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('common.loading');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// Pretty-prints a count with the locale's grouping separator
// (1,234 in EN, ١٬٢٣٤ in AR). Falls back to a plain string when
// Intl isn't available (very old browsers — defensive only).
function formatCount(n: number): string {
    try {
        return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-OM' : 'en-GB').format(n);
    } catch {
        return String(n);
    }
}

// Compact "X minutes ago" / "Y hours ago" relative time for the
// activity feed. Intl.RelativeTimeFormat handles both locales.
function timeAgo(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const diffSec = Math.floor((Date.now() - date.getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(locale.value === 'ar' ? 'ar-OM' : 'en-GB', { numeric: 'auto' });

    if (diffSec < 60) {
        return rtf.format(-diffSec, 'second');
    }
    if (diffSec < 3600) {
        return rtf.format(-Math.floor(diffSec / 60), 'minute');
    }
    if (diffSec < 86400) {
        return rtf.format(-Math.floor(diffSec / 3600), 'hour');
    }
    return rtf.format(-Math.floor(diffSec / 86400), 'day');
}

// Tone mapping for the merchant status chip. Mirrors the colour
// language used in Merchants/Index so the platform feels coherent.
function statusTone(value: CompanyStatus | null): StatusTone {
    switch (value) {
        case 'active': return 'green';
        case 'onboarding': return 'amber';
        case 'suspended': return 'rose';
        case 'inactive': return 'slate';
        default: return 'slate';
    }
}

function statusLabel(value: CompanyStatus | null): string {
    if (!value) {
        return '—';
    }
    const key = `merchants.status_options.${value}`;
    const translated = t(key);
    return translated === key ? value : translated;
}

// Donut chart wants the four "interesting" device statuses as
// coloured segments. Registered + assigned + active are normal
// pipeline states; inactive + blocked are flagged for review.
// We always emit four segments (even zero ones) so the legend
// stays predictable across loads.
function deviceDonutSegments(byStatus: Record<DeviceStatus, number>) {
    return [
        { label: t('devices.status_options.active'),     value: byStatus.active ?? 0,     color: '#0f766e' },
        { label: t('devices.status_options.assigned'),   value: byStatus.assigned ?? 0,   color: '#0284c7' },
        { label: t('devices.status_options.registered'), value: byStatus.registered ?? 0, color: '#f59e0b' },
        { label: t('devices.status_options.inactive'),   value: byStatus.inactive ?? 0,   color: '#94a3b8' },
    ];
}

/**
 * Event-label translation for the activity feed. Mirrors the
 * approach in the Audit Log viewer — falls back to the raw event
 * string when no translation exists.
 */
function eventLabel(event: string): string {
    const key = `audit_log.events.${event}`;
    const translated = t(key);
    return translated === key ? event : translated;
}

// Pick the right localised merchant name for the recent-onboarding
// table — Arabic when present + locale is AR, English otherwise.
function merchantName(row: { name: string; name_ar: string | null }): string {
    if (locale.value === 'ar' && row.name_ar) {
        return row.name_ar;
    }
    return row.name;
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header strip. Quick-actions are gated by their
                 respective permissions — Onboarding-Officer-only
                 admins shouldn't see the Register Device button. -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('dashboard.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950 md:text-4xl">
                        {{ t('dashboard.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('dashboard.subtitle') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <RouterLink
                        v-if="can(PlatformPermission.DevicesRegister)"
                        to="/admin/devices/new"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-50 hover:shadow-md"
                    >
                        <MonitorSmartphone class="size-4" />
                        {{ t('dashboard.actions.register_device') }}
                    </RouterLink>
                    <RouterLink
                        v-if="can(PlatformPermission.MerchantsCreate)"
                        to="/admin/merchants/new"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                    >
                        <Plus class="size-4" />
                        {{ t('dashboard.actions.new_merchant') }}
                    </RouterLink>
                </div>
            </div>

            <!-- Error banner — replaces the data grid when the
                 single fetch fails. Retry just re-runs load(). -->
            <div
                v-if="error"
                class="flex items-center justify-between gap-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
            >
                <span>{{ error }}</span>
                <button
                    type="button"
                    class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-rose-700 transition hover:bg-rose-100"
                    @click="load"
                >
                    {{ t('common.retry') }}
                </button>
            </div>

            <!-- Loading skeleton — four placeholder tiles so the
                 layout doesn't jump when the data lands. -->
            <div v-if="loading && !summary" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="i in 4" :key="i" class="h-32 animate-pulse rounded-lg border border-slate-200 bg-white" />
            </div>

            <template v-if="summary">
                <!-- KPI tiles. Sales / round-up are intentionally
                     omitted (no POS data yet). The 4th tile shows
                     audit-log activity volume as a stand-in metric
                     so the row stays balanced. -->
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        :label="t('dashboard.tiles.companies')"
                        :value="formatCount(summary.companies.total)"
                        :change="t('dashboard.tiles.companies_change', { active: summary.companies.by_status.active ?? 0, onboarding: summary.companies.by_status.onboarding ?? 0 })"
                        tone="teal"
                        :icon="Building2"
                    />
                    <MetricCard
                        :label="t('dashboard.tiles.branches')"
                        :value="formatCount(summary.branches.total)"
                        :change="t('dashboard.tiles.branches_change')"
                        tone="blue"
                        :icon="ShieldCheck"
                    />
                    <MetricCard
                        :label="t('dashboard.tiles.devices')"
                        :value="formatCount(summary.devices.total)"
                        :change="t('dashboard.tiles.devices_change', { unassigned: summary.devices.unassigned })"
                        tone="amber"
                        :icon="MonitorSmartphone"
                    />
                    <MetricCard
                        :label="t('dashboard.tiles.activity')"
                        :value="formatCount(summary.recent_activity.length)"
                        :change="t('dashboard.tiles.activity_change')"
                        tone="rose"
                        :icon="History"
                    />
                </div>

                <!-- Device distribution donut. Sales trend chart
                     intentionally omitted until POS data exists. -->
                <div class="grid gap-6 xl:grid-cols-[1.45fr_0.85fr]">
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ t('dashboard.fleet_status_title') }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ t('dashboard.fleet_status_subtitle') }}</p>
                            </div>
                            <MonitorSmartphone class="size-5 text-slate-400" />
                        </div>
                        <!-- Three big numbers: total, assigned,
                             unassigned — mirrors the language a
                             support agent would use in a ticket. -->
                        <dl class="mt-6 grid gap-4 sm:grid-cols-3">
                            <div class="rounded-lg bg-slate-50 p-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard.fleet.total') }}</dt>
                                <dd class="mt-2 text-2xl font-semibold text-slate-950">{{ formatCount(summary.devices.total) }}</dd>
                            </div>
                            <div class="rounded-lg bg-teal-50 p-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ t('dashboard.fleet.assigned') }}</dt>
                                <dd class="mt-2 text-2xl font-semibold text-teal-900">
                                    {{ formatCount((summary.devices.by_status.assigned ?? 0) + (summary.devices.by_status.active ?? 0)) }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-amber-50 p-4">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ t('dashboard.fleet.unassigned') }}</dt>
                                <dd class="mt-2 text-2xl font-semibold text-amber-900">{{ formatCount(summary.devices.unassigned) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ t('dashboard.device_donut_title') }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ t('dashboard.device_donut_subtitle') }}</p>
                            </div>
                            <MonitorSmartphone class="size-5 text-slate-400" />
                        </div>
                        <div class="mt-7">
                            <DonutChart :segments="deviceDonutSegments(summary.devices.by_status)" />
                        </div>
                    </section>
                </div>

                <!-- Recent merchants + activity feed. Both link out
                     to their full pages for follow-up. -->
                <div class="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ t('dashboard.recent_merchants_title') }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ t('dashboard.recent_merchants_subtitle') }}</p>
                            </div>
                            <RouterLink
                                v-if="can(PlatformPermission.MerchantsView)"
                                to="/admin/merchants"
                                class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                {{ t('dashboard.view_all') }}
                            </RouterLink>
                        </div>

                        <div v-if="summary.recent_merchants.length === 0" class="p-10 text-center text-sm font-medium text-slate-500">
                            {{ t('dashboard.empty_recent_merchants') }}
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard.recent_merchants_table.name') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard.recent_merchants_table.contact') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard.recent_merchants_table.branches') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard.recent_merchants_table.devices') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('dashboard.recent_merchants_table.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr
                                        v-for="merchant in summary.recent_merchants"
                                        :key="merchant.uuid"
                                        class="transition hover:bg-slate-50"
                                    >
                                        <td class="px-5 py-4">
                                            <RouterLink
                                                :to="`/admin/merchants/${merchant.uuid}`"
                                                class="text-sm font-semibold text-slate-950 hover:text-teal-700"
                                            >
                                                {{ merchantName(merchant) }}
                                            </RouterLink>
                                        </td>
                                        <td class="px-5 py-4 text-sm text-slate-600">{{ merchant.contact_name ?? '—' }}</td>
                                        <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ formatCount(merchant.branches_count) }}</td>
                                        <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ formatCount(merchant.devices_count) }}</td>
                                        <td class="px-5 py-4">
                                            <StatusPill :label="statusLabel(merchant.status)" :tone="statusTone(merchant.status)" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- Activity feed pulls from pos_audit_logs.
                         Same data the Audit Log viewer page renders
                         but trimmed to a vertical timeline here. -->
                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-slate-950">{{ t('dashboard.activity_title') }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ t('dashboard.activity_subtitle') }}</p>
                            </div>
                            <ClipboardList class="size-5 text-slate-400" />
                        </div>

                        <div v-if="summary.recent_activity.length === 0" class="mt-6 text-sm text-slate-500">
                            {{ t('dashboard.empty_activity') }}
                        </div>

                        <ul v-else class="mt-6 space-y-4">
                            <li
                                v-for="entry in summary.recent_activity"
                                :key="entry.id"
                                class="flex gap-3"
                            >
                                <span class="mt-1.5 size-2.5 shrink-0 rounded-full bg-teal-500" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold leading-5 text-slate-800">
                                        {{ eventLabel(entry.event) }}
                                    </span>
                                    <span class="mt-1 block text-xs font-medium text-slate-500">
                                        <template v-if="entry.actor">{{ entry.actor.name }} · </template>
                                        {{ timeAgo(entry.occurred_at) }}
                                    </span>
                                </span>
                            </li>
                        </ul>

                        <RouterLink
                            v-if="can(PlatformPermission.AuditLogsView)"
                            to="/admin/audit-log"
                            class="mt-5 inline-flex w-full items-center justify-center rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            {{ t('dashboard.view_full_log') }}
                        </RouterLink>
                    </section>
                </div>
            </template>
        </section>
    </AdminLayout>
</template>
