<script setup lang="ts">
/**
 * Merchant commission profile — TWO independent sections, one per money flow:
 *
 *   CARD sales           — platform / bank / other lines. Applied automatically
 *                          when a sale is paid by card (the money the platform
 *                          holds and later pays out).
 *   CASH & BANK POS      — platform / other lines (never bank — an acquirer fee
 *                          can't exist on merchant-collected money). Applied
 *                          automatically when a sale is paid in cash or on the
 *                          bank's own POS (the money the merchant holds and is
 *                          invoiced for).
 *
 * The server stores both in one profile with a channel tag per line
 * (applies_to: card | cash_bank); the sale-time recorder picks the right
 * section by each tender's method, so nothing is ever mixed. A legacy line
 * saved as "all sales" is shown in BOTH sections and splits into the two
 * channels on the next save (behaviourally identical). Each section's split
 * must fit inside its own 100%; the merchant keeps each remainder.
 */
import { Loader2, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import {
    getMerchantCommissionProfile,
    updateMerchantCommissionProfile,
    type CommissionAppliesTo,
    type CommissionPartyType,
} from '@/lib/api/merchants';

const props = defineProps<{
    merchantUuid: string;
    canManage: boolean;
}>();

const { t } = useI18n();

interface SectionShare {
    party_type: CommissionPartyType;
    label: string;
    percent: number;
}

const loading = ref(true);
const loadError = ref<string | null>(null);
const saving = ref(false);
const saveError = ref<string | null>(null);
const saved = ref(false);

const isActive = ref(true);
// One editable list per money flow — the section IS the channel.
const cardShares = ref<SectionShare[]>([]);
const cashShares = ref<SectionShare[]>([]);

function partyLabel(type: CommissionPartyType): string {
    return t(`merchants.commission.party_options.${type}`);
}

const round2 = (n: number): number => Math.round(n * 100) / 100;
const sectionTotal = (list: SectionShare[]): number => round2(list.reduce((sum, s) => sum + (Number(s.percent) || 0), 0));

const cardTotal = computed(() => sectionTotal(cardShares.value));
const cashTotal = computed(() => sectionTotal(cashShares.value));
const merchantCard = computed(() => round2(100 - cardTotal.value));
const merchantCash = computed(() => round2(100 - cashTotal.value));
const cardOver = computed(() => cardTotal.value > 100);
const cashOver = computed(() => cashTotal.value > 100);
const overLimit = computed(() => cardOver.value || cashOver.value);
const canSave = computed(() => props.canManage && !saving.value && !overLimit.value);

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await getMerchantCommissionProfile(props.merchantUuid);
        isActive.value = data.is_active;
        const card: SectionShare[] = [];
        const cash: SectionShare[] = [];
        for (const s of data.shares) {
            const line: SectionShare = { party_type: s.party_type, label: s.label, percent: Number(s.percent) };
            const channel: CommissionAppliesTo = s.party_type === 'bank' ? 'card' : (s.applies_to ?? 'all');
            // A legacy "all sales" line belongs to both flows — show it in both
            // sections (it becomes two channel lines on the next save, which
            // records identically).
            if (channel === 'card' || channel === 'all') card.push({ ...line });
            if (s.party_type !== 'bank' && (channel === 'cash_bank' || channel === 'all')) cash.push({ ...line });
        }
        cardShares.value = card;
        cashShares.value = cash;
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : t('merchants.commission.load_error');
    } finally {
        loading.value = false;
    }
}

function addLine(section: 'card' | 'cash'): void {
    saved.value = false;
    const list = section === 'card' ? cardShares : cashShares;
    list.value.push({ party_type: 'platform', label: partyLabel('platform'), percent: 0 });
}

function removeLine(section: 'card' | 'cash', index: number): void {
    saved.value = false;
    (section === 'card' ? cardShares : cashShares).value.splice(index, 1);
}

function onPartyTypeChange(share: SectionShare, previousType: CommissionPartyType): void {
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
        const toPayload = (list: SectionShare[], channel: CommissionAppliesTo) =>
            list.map((s) => ({
                party_type: s.party_type,
                label: s.label.trim() || partyLabel(s.party_type),
                percent: Number(s.percent) || 0,
                applies_to: channel,
            }));
        const { data } = await updateMerchantCommissionProfile(props.merchantUuid, {
            is_active: isActive.value,
            shares: [...toPayload(cardShares.value, 'card'), ...toPayload(cashShares.value, 'cash_bank')],
        });
        isActive.value = data.is_active;
        // Re-distribute from the server truth.
        const card: SectionShare[] = [];
        const cash: SectionShare[] = [];
        for (const s of data.shares) {
            const line: SectionShare = { party_type: s.party_type, label: s.label, percent: Number(s.percent) };
            const channel: CommissionAppliesTo = s.party_type === 'bank' ? 'card' : (s.applies_to ?? 'all');
            if (channel === 'card' || channel === 'all') card.push({ ...line });
            if (s.party_type !== 'bank' && (channel === 'cash_bank' || channel === 'all')) cash.push({ ...line });
        }
        cardShares.value = card;
        cashShares.value = cash;
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
                <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('merchants.commission.split_subtitle') }}</p>
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

        <div v-else class="mt-5 space-y-6">
            <!-- ═══════════ Section 1: CARD sales ═══════════ -->
            <div class="rounded-xl border border-indigo-200 bg-indigo-50/30 p-4">
                <h4 class="text-sm font-bold uppercase tracking-wide text-indigo-700">{{ t('merchants.commission.card_section_title') }}</h4>
                <p class="mb-3 mt-0.5 text-xs text-slate-500">{{ t('merchants.commission.card_section_hint') }}</p>

                <div v-if="cardShares.length" class="space-y-2">
                    <div class="hidden grid-cols-[1fr_1.4fr_7rem_2.5rem] gap-3 px-1 text-xs font-semibold uppercase tracking-wide text-slate-400 sm:grid">
                        <span>{{ t('merchants.commission.party_type') }}</span>
                        <span>{{ t('merchants.commission.label') }}</span>
                        <span>{{ t('merchants.commission.percent') }}</span>
                        <span></span>
                    </div>
                    <div
                        v-for="(share, index) in cardShares"
                        :key="index"
                        class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_1.4fr_7rem_2.5rem] sm:items-center"
                    >
                        <select
                            :value="share.party_type"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                            :disabled="!canManage"
                            @change="(e) => { const prev = share.party_type; share.party_type = (e.target as HTMLSelectElement).value as CommissionPartyType; onPartyTypeChange(share, prev); }"
                        >
                            <option value="platform">{{ partyLabel('platform') }}</option>
                            <option value="bank">{{ partyLabel('bank') }}</option>
                            <option value="other">{{ partyLabel('other') }}</option>
                        </select>
                        <input
                            v-model="share.label"
                            type="text"
                            maxlength="120"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
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
                                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 pr-7 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                                :disabled="!canManage"
                                @input="saved = false"
                            />
                            <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-slate-400">%</span>
                        </div>
                        <button
                            v-if="canManage"
                            type="button"
                            class="flex size-9 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 transition hover:border-rose-300 hover:text-rose-600"
                            :title="t('merchants.commission.remove')"
                            @click="removeLine('card', index)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>
                <p v-else class="rounded-md bg-white/70 px-4 py-3 text-sm text-slate-500">{{ t('merchants.commission.card_empty') }}</p>

                <button
                    v-if="canManage"
                    type="button"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-dashed border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700 transition hover:border-indigo-400 hover:bg-white"
                    @click="addLine('card')"
                >
                    <Plus class="size-4" />
                    {{ t('merchants.commission.add_line') }}
                </button>

                <dl class="mt-3 grid gap-1.5 rounded-lg border border-indigo-100 bg-white p-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">{{ t('merchants.commission.parties_total') }}</dt>
                        <dd class="font-semibold tabular-nums" :class="cardOver ? 'text-rose-700' : 'text-slate-900'">{{ cardTotal.toFixed(2) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 pt-1.5">
                        <dt class="font-semibold text-teal-800">{{ t('merchants.commission.merchant_share') }}</dt>
                        <dd class="font-bold tabular-nums text-teal-700">{{ merchantCard.toFixed(2) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 pt-1.5">
                        <dt class="font-semibold text-slate-900">{{ t('merchants.commission.grand_total') }}</dt>
                        <dd class="font-bold tabular-nums" :class="cardOver ? 'text-rose-700' : 'text-slate-900'">{{ (cardTotal + merchantCard).toFixed(2) }}%</dd>
                    </div>
                </dl>
                <p v-if="cardOver" class="mt-2 text-sm font-medium text-rose-700">{{ t('merchants.commission.over_limit') }}</p>
            </div>

            <!-- ═══════════ Section 2: CASH & BANK POS sales ═══════════ -->
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/30 p-4">
                <h4 class="text-sm font-bold uppercase tracking-wide text-emerald-700">{{ t('merchants.commission.cash_section_title') }}</h4>
                <p class="mb-3 mt-0.5 text-xs text-slate-500">{{ t('merchants.commission.cash_section_hint') }}</p>

                <div v-if="cashShares.length" class="space-y-2">
                    <div class="hidden grid-cols-[1fr_1.4fr_7rem_2.5rem] gap-3 px-1 text-xs font-semibold uppercase tracking-wide text-slate-400 sm:grid">
                        <span>{{ t('merchants.commission.party_type') }}</span>
                        <span>{{ t('merchants.commission.label') }}</span>
                        <span>{{ t('merchants.commission.percent') }}</span>
                        <span></span>
                    </div>
                    <div
                        v-for="(share, index) in cashShares"
                        :key="index"
                        class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_1.4fr_7rem_2.5rem] sm:items-center"
                    >
                        <!-- No bank option here — an acquirer fee can't exist on
                             money the merchant collected directly. -->
                        <select
                            :value="share.party_type"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                            :disabled="!canManage"
                            @change="(e) => { const prev = share.party_type; share.party_type = (e.target as HTMLSelectElement).value as CommissionPartyType; onPartyTypeChange(share, prev); }"
                        >
                            <option value="platform">{{ partyLabel('platform') }}</option>
                            <option value="other">{{ partyLabel('other') }}</option>
                        </select>
                        <input
                            v-model="share.label"
                            type="text"
                            maxlength="120"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
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
                                class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 pr-7 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500 disabled:bg-slate-50"
                                :disabled="!canManage"
                                @input="saved = false"
                            />
                            <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-slate-400">%</span>
                        </div>
                        <button
                            v-if="canManage"
                            type="button"
                            class="flex size-9 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400 transition hover:border-rose-300 hover:text-rose-600"
                            :title="t('merchants.commission.remove')"
                            @click="removeLine('cash', index)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>
                <p v-else class="rounded-md bg-white/70 px-4 py-3 text-sm text-slate-500">{{ t('merchants.commission.cash_empty') }}</p>

                <button
                    v-if="canManage"
                    type="button"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-dashed border-emerald-300 px-3 py-2 text-sm font-semibold text-emerald-700 transition hover:border-emerald-400 hover:bg-white"
                    @click="addLine('cash')"
                >
                    <Plus class="size-4" />
                    {{ t('merchants.commission.add_line') }}
                </button>

                <dl class="mt-3 grid gap-1.5 rounded-lg border border-emerald-100 bg-white p-3 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-slate-500">{{ t('merchants.commission.parties_total') }}</dt>
                        <dd class="font-semibold tabular-nums" :class="cashOver ? 'text-rose-700' : 'text-slate-900'">{{ cashTotal.toFixed(2) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 pt-1.5">
                        <dt class="font-semibold text-teal-800">{{ t('merchants.commission.merchant_share') }}</dt>
                        <dd class="font-bold tabular-nums text-teal-700">{{ merchantCash.toFixed(2) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 pt-1.5">
                        <dt class="font-semibold text-slate-900">{{ t('merchants.commission.grand_total') }}</dt>
                        <dd class="font-bold tabular-nums" :class="cashOver ? 'text-rose-700' : 'text-slate-900'">{{ (cashTotal + merchantCash).toFixed(2) }}%</dd>
                    </div>
                </dl>
                <p v-if="cashOver" class="mt-2 text-sm font-medium text-rose-700">{{ t('merchants.commission.over_limit') }}</p>
            </div>

            <p v-if="!canManage" class="text-xs text-slate-500">{{ t('merchants.commission.active_hint') }}</p>

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
