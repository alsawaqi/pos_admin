<script setup lang="ts">
/**
 * Marketing → Sliders — list of sliders. Each is an ordered loop of approved
 * content targeted at branches/devices. Create / edit opens the Builder.
 */

import { BarChart3, Images, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import { deleteSlider, listSliders, type Slider } from '@/lib/api/marketingSliders';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const router = useRouter();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.MarketingSlidersManage));

const sliders = ref<Slider[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);

const statusClass: Record<string, string> = {
    draft: 'bg-slate-200 text-slate-600',
    active: 'bg-emerald-100 text-emerald-700',
    paused: 'bg-amber-100 text-amber-700',
};

function targetSummary(s: Slider): string {
    const n = s.targets_count ?? 0;
    return n === 0 ? 'Not assigned' : `${n} device${n > 1 ? 's' : ''}`;
}

function fmtDate(s: string | null): string {
    if (!s) return '—';
    const d = new Date(s);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        sliders.value = (await listSliders()).data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load sliders';
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());

function openCreate(): void {
    void router.push({ name: 'admin.marketing.sliders.create' });
}

function openEdit(s: Slider): void {
    void router.push({ name: 'admin.marketing.sliders.edit', params: { uuid: s.uuid } });
}

function openAudience(s: Slider): void {
    void router.push({ name: 'admin.marketing.sliders.audience', params: { uuid: s.uuid } });
}

async function remove(s: Slider): Promise<void> {
    if (!window.confirm(`Delete the slider "${s.name}"? Devices will stop showing it.`)) return;
    try {
        await deleteSlider(s.uuid);
        flash.value = { type: 'success', text: 'Slider deleted.' };
        await load();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.payload as { message?: string } | null)?.message : undefined;
        flash.value = { type: 'error', text: msg || (err instanceof Error ? err.message : 'Delete failed') };
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Marketing</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Sliders</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Group approved content into a looping slider and target it at branches or devices.
                    </p>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    New slider
                </button>
            </div>

            <div
                v-if="flash"
                class="rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="flash.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'"
            >{{ flash.text }}</div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">Loading…</div>
                <div v-else-if="sliders.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Images class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">No sliders yet.</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Items</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Plays at</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Interval</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Window</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                                <th v-if="canManage" class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="s in sliders" :key="s.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4 text-sm font-semibold text-slate-950">{{ s.name }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ s.items_count ?? 0 }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ targetSummary(s) }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ s.loop_interval_seconds }}s</td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ fmtDate(s.starts_at) }} → {{ fmtDate(s.ends_at) }}</td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider" :class="statusClass[s.status]">{{ s.status }}</span>
                                </td>
                                <td v-if="canManage" class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Audience analytics" @click="openAudience(s)">
                                            <BarChart3 class="size-4" />
                                        </button>
                                        <button type="button" class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Edit slider" @click="openEdit(s)">
                                            <Pencil class="size-4" />
                                        </button>
                                        <button type="button" class="grid size-8 place-items-center rounded-lg text-rose-600 hover:bg-rose-50" aria-label="Delete slider" @click="remove(s)">
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </AdminLayout>
</template>
