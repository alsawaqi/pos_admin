<script setup lang="ts">
import { Loader2, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import {
    getMerchantCommissionProfile,
    updateMerchantCommissionProfile,
    type CommissionPartyType,
    type CommissionShare,
} from '@/lib/api/merchants';

const props = defineProps<{
    merchantUuid: string;
    canManage: boolean;
}>();

const { t } = useI18n();

const PARTY_TYPES: CommissionPartyType[] = ['platform', 'bank', 'other'];

const loading = ref(true);
const loadError = ref<string | null>(null);
const saving = ref(false);
const saveError = ref<string | null>(null);
const saved = ref(false);

const isActive = ref(true);
// Local editable copy of the share lines. percent is kept as a number so
// the running total + merchant residual recompute live as the admin types.
const shares = ref<CommissionShare[]>([]);

function partyLabel(type: CommissionPartyType): string {
    return t(`merchants.commission.party_options.${type}`);
}

const partiesTotal = computed(() =>
    Math.round(shares.value.reduce((sum, s) => sum + (Number(s.percent) || 0), 0) * 100) / 100,
);
const merchantPercent = computed(() => Math.round((100 - partiesTotal.value) * 100) / 100);
const overLimit = computed(() => partiesTotal.value > 100);
const canSave = computed(() => props.canManage && !saving.value && !overLimit.value);

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await getMerchantCommissionProfile(props.merchantUuid);
        isActive.value = data.is_active;
        shares.value = data.shares.map((s) => ({
            party_type: s.party_type,
            label: s.label,
            percent: Number(s.percent),
        }));
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : t('merchants.commission.load_error');
    } finally {
        loading.value = false;
    }
}

function addLine(): void {
    saved.value = false;
    shares.value.push({ party_type: 'platform', label: partyLabel('platform'), percent: 0 });
}

function removeLine(index: number): void {
    saved.value = false;
    shares.value.splice(index, 1);
}

function onPartyTypeChange(share: CommissionShare, previousType: CommissionPartyType): void {
    saved.value = false;
    // Auto-fill the label when it was still the default for the old type so
    // switching Platform → Bank renames the line without extra typing.
    if (!share.label.trim() || share.label === partyLabel(previousType)) {
        share.label = partyLabel(share.party_type);
    }
}

async function save(): Promise<void> {
    if (!canSave.value) {
        return;
    }
    saving.value = true;
    saveError.value = null;
    saved.value = false;
    try {
        const { data } = await updateMerchantCommissionProfile(props.merchantUuid, {
            is_active: isActive.value,
            shares: shares.value.map((s) => ({
                party_type: s.party_type,
                label: s.label.trim() || partyLabel(s.party_type),
                percent: Number(s.percent) || 0,
            })),
        });
        isActive.value = data.is_active;
        shares.value = data.shares.map((s) => ({
            party_type: s.party_type,
            label: s.label,
            percent: Number(s.percent),
        }));
        saved.value = true;
    } catch (e) {
        saveError.value = e instanceof ApiError ? e.message : t('merchants.commission.save_error');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                    {{ t('merchants.commission.title') }}
                </h3>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('merchants.commission.subtitle') }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input
                    v-model="isActive"
                    type="checkbox"
                    class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                    :disabled="!canManage"
                />
                {{ t('merchants.commission.active_label') }}
            </label>
        </header>

        <div v-if="loading" class="mt-6 flex items-center gap-2 text-sm text-slate-500">
            <Loader2 class="size-4 animate-spin" />
        </div>

        <p v-else-if="loadError" class="mt-6 text-sm font-medium text-rose-700">{{ loadError }}</p>

        <div v-else class="mt-5 space-y-4">
            <!-- Share lines -->
            <div v-if="shares.length" class="space-y-2">
                <div class="hidden grid-cols-[1fr_1.4fr_7rem_2.5rem] gap-3 px-1 text-xs font-semibold uppercase tracking-wide text-slate-400 sm:grid">
                    <span>{{ t('merchants.commission.party_type') }}</span>
                    <span>{{ t('merchants.commission.label') }}</span>
                    <span>{{ t('merchants.commission.percent') }}</span>
                    <span></span>
                </div>
                <div
                    v-for="(share, index) in shares"
                    :key="index"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_1.4fr_7rem_2.5rem] sm:items-center"
                >
                    <select
                        :value="share.party_type"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                        :disabled="!canManage"
                        @change="(e) => { const prev = share.party_type; share.party_type = (e.target as HTMLSelectElement).value as CommissionPartyType; onPartyTypeChange(share, prev); }"
                    >
                        <option v-for="type in PARTY_TYPES" :key="type" :value="type">{{ partyLabel(type) }}</option>
                    </select>
                    <input
                        v-model="share.label"
                        type="text"
                        maxlength="120"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                        :disabled="!canManage"
                        @input="saved = false"
                    />
                    <div class="relative">
                        <input
                            v-model.number="share.percent"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 pr-7 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                            :disabled="!canManage"
                            @input="saved = false"
                        />
                        <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-slate-400">%</span>
                    </div>
                    <button
                        v-if="canManage"
                        type="button"
                        class="flex size-9 items-center justify-center rounded-md border border-slate-200 text-slate-400 transition hover:border-rose-300 hover:text-rose-600"
                        :title="t('merchants.commission.remove')"
                        @click="removeLine(index)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>

            <p v-else class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-500">
                {{ t('merchants.commission.empty') }}
            </p>

            <button
                v-if="canManage"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-dashed border-slate-300 px-3 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-400 hover:text-teal-700"
                @click="addLine"
            >
                <Plus class="size-4" />
                {{ t('merchants.commission.add_line') }}
            </button>

            <!-- Totals -->
            <dl class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500">{{ t('merchants.commission.parties_total') }}</dt>
                    <dd class="font-semibold tabular-nums" :class="overLimit ? 'text-rose-700' : 'text-slate-900'">
                        {{ partiesTotal.toFixed(2) }}%
                    </dd>
                </div>
                <div class="flex items-center justify-between border-t border-slate-200 pt-2">
                    <dt class="font-semibold text-teal-800">{{ t('merchants.commission.merchant_share') }}</dt>
                    <dd class="text-lg font-bold tabular-nums text-teal-700">{{ merchantPercent.toFixed(2) }}%</dd>
                </div>
            </dl>

            <p v-if="!canManage" class="text-xs text-slate-500">{{ t('merchants.commission.active_hint') }}</p>

            <p v-if="overLimit" class="text-sm font-medium text-rose-700">{{ t('merchants.commission.over_limit') }}</p>

            <div v-if="canManage" class="flex items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!canSave"
                    @click="save"
                >
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    {{ saving ? t('merchants.commission.saving') : t('merchants.commission.save') }}
                </button>
                <span v-if="saved" class="text-sm font-medium text-teal-700">{{ t('merchants.commission.saved') }}</span>
                <span v-if="saveError" class="text-sm font-medium text-rose-700">{{ saveError }}</span>
            </div>
        </div>
    </section>
</template>
