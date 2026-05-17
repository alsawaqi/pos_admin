<script setup lang="ts">
import DonutChart from '@/Components/Admin/DonutChart.vue';
import MetricCard from '@/Components/Admin/MetricCard.vue';
import StatusPill from '@/Components/Admin/StatusPill.vue';
import TrendLineChart from '@/Components/Admin/TrendLineChart.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    Building2,
    CircleDollarSign,
    HandCoins,
    MonitorSmartphone,
    Plus,
    ShieldCheck,
} from 'lucide-vue-next';

const metrics = [
    {
        label: 'Onboarded Merchants',
        value: '128',
        change: '+12 this month',
        tone: 'teal',
        icon: Building2,
    },
    {
        label: 'Active Branches',
        value: '342',
        change: '+27 active locations',
        tone: 'blue',
        icon: ShieldCheck,
    },
    {
        label: 'Assigned Devices',
        value: '419',
        change: '96% paired',
        tone: 'amber',
        icon: MonitorSmartphone,
    },
    {
        label: 'Round-Up Donations',
        value: '8.42K',
        change: '+21.8% collected',
        tone: 'rose',
        icon: HandCoins,
    },
] as const;

const merchantRows = [
    { name: 'Qahwa House', contact: 'Salim Al-Harthy', branches: 8, devices: 22, status: 'Active', tone: 'green' },
    { name: 'Muscat Bites', contact: 'Maha Al-Rashdi', branches: 3, devices: 9, status: 'Onboarding', tone: 'amber' },
    { name: 'Harbor Grill', contact: 'Yousuf Said', branches: 5, devices: 14, status: 'Active', tone: 'green' },
    { name: 'Nizwa Roastery', contact: 'Huda Al-Kindi', branches: 2, devices: 6, status: 'Review', tone: 'blue' },
] as const;

const deviceEvents = [
    { title: 'POS-MCT-004 synced branch menu', time: '2 min ago', tone: 'green' },
    { title: 'POS-SHR-118 activation token created', time: '11 min ago', tone: 'blue' },
    { title: 'POS-NZW-021 missed last heartbeat', time: '24 min ago', tone: 'amber' },
    { title: 'POS-MCT-087 reassigned to Azaiba', time: '41 min ago', tone: 'slate' },
] as const;
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Platform Control</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950 md:text-4xl">
                        POS Admin Dashboard
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Manage merchant accounts, branches, POS devices, admin users, and pilot readiness from one secure workspace.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800 shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-50 hover:shadow-md"
                    >
                        <MonitorSmartphone class="size-4" />
                        Register Device
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                    >
                        <Plus class="size-4" />
                        New Merchant
                    </button>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    v-for="metric in metrics"
                    :key="metric.label"
                    :label="metric.label"
                    :value="metric.value"
                    :change="metric.change"
                    :tone="metric.tone"
                    :icon="metric.icon"
                />
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.45fr_0.85fr]">
                <TrendLineChart
                    :points="[42, 58, 51, 78, 84, 92, 118, 126]"
                    :labels="['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun', 'Now']"
                />

                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">Device Distribution</h2>
                            <p class="mt-1 text-sm text-slate-500">Current pilot hardware status</p>
                        </div>
                        <MonitorSmartphone class="size-5 text-slate-400" />
                    </div>
                    <div class="mt-7">
                        <DonutChart
                            :segments="[
                                { label: 'Active', value: 312, color: '#0f766e' },
                                { label: 'Assigned', value: 82, color: '#f59e0b' },
                                { label: 'Inactive', value: 25, color: '#94a3b8' },
                            ]"
                        />
                    </div>
                </section>
            </div>

            <div class="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">Recent Merchant Onboarding</h2>
                            <p class="mt-1 text-sm text-slate-500">Accounts created and prepared by platform admins</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            View all
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Merchant</th>
                                    <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Contact</th>
                                    <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Branches</th>
                                    <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Devices</th>
                                    <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr
                                    v-for="merchant in merchantRows"
                                    :key="merchant.name"
                                    class="transition hover:bg-slate-50"
                                >
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-950">{{ merchant.name }}</td>
                                    <td class="px-5 py-4 text-sm text-slate-600">{{ merchant.contact }}</td>
                                    <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ merchant.branches }}</td>
                                    <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ merchant.devices }}</td>
                                    <td class="px-5 py-4">
                                        <StatusPill :label="merchant.status" :tone="merchant.tone" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">Live Device Activity</h2>
                            <p class="mt-1 text-sm text-slate-500">Recent sync and assignment events</p>
                        </div>
                        <CircleDollarSign class="size-5 text-slate-400" />
                    </div>

                    <div class="mt-6 space-y-4">
                        <div
                            v-for="event in deviceEvents"
                            :key="event.title"
                            class="flex gap-3"
                        >
                            <span
                                class="mt-1 size-2.5 shrink-0 rounded-full"
                                :class="{
                                    'bg-emerald-500': event.tone === 'green',
                                    'bg-sky-500': event.tone === 'blue',
                                    'bg-amber-500': event.tone === 'amber',
                                    'bg-slate-400': event.tone === 'slate',
                                }"
                            />
                            <span>
                                <span class="block text-sm font-semibold leading-5 text-slate-800">{{ event.title }}</span>
                                <span class="mt-1 block text-xs font-medium text-slate-500">{{ event.time }}</span>
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </AdminLayout>
</template>
