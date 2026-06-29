<script setup lang="ts">
import { Banknote, MapPin,
    Bell,
    Building2,
    ChevronDown,
    ClipboardCheck,
    ClipboardList,
    Gauge,
    HandCoins,
    Hourglass,
    Images,
    KeyRound,
    LogOut,
    Megaphone,
    Menu,
    MonitorSmartphone,
    Search,
    Settings,
    ShieldCheck,
    Users,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, type Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import FullscreenLoader from '@/Components/FullscreenLoader.vue';
import { useFreshCsrfNativePost } from '@/composables/useFreshCsrfNativePost';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';
import { authState } from '@/stores/auth';

interface NavItem {
    key: string;
    to: string;
    icon: Component;
    permissions: readonly string[];
}

const sidebarOpen = ref(false);
const { t } = useI18n();
const { canAny } = usePermissions();
const csrfToken = ref('');
const {
    isSubmitting: isLoggingOut,
    submitWithFreshCsrf: submitLogoutWithFreshCsrf,
} = useFreshCsrfNativePost(csrfToken);

onMounted(() => {
    csrfToken.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
});

interface NavGroup {
    key: string;
    items: readonly NavItem[];
}

// Dashboard sits above the grouped sections — the landing page, always shown.
const dashboardItem: NavItem = { key: 'dashboard', to: '/admin', icon: Gauge, permissions: [] };

// The sidebar is split into three sections. "Settings", the reference-data
// catalogues, and team/roles/audit all live under "Admin Configuration" because
// they're shared by BOTH the POS and the marketing sides. Group header label =
// t(`nav_group.${group.key}`); item label = t(`nav.${item.key}`).
const navigationGroups: readonly NavGroup[] = [
    {
        key: 'point_of_sale',
        items: [
            { key: 'merchants', to: '/admin/merchants', icon: Building2, permissions: [PlatformPermission.MerchantsView] },
            { key: 'devices', to: '/admin/devices', icon: MonitorSmartphone, permissions: [PlatformPermission.DevicesView] },
            { key: 'orders', to: '/admin/orders', icon: Banknote, permissions: [PlatformPermission.ReportsView] },
            { key: 'settlements', to: '/admin/settlements', icon: Wallet, permissions: [PlatformPermission.ReportsView] },
            { key: 'roundup_donations', to: '/admin/roundup-donations', icon: HandCoins, permissions: [PlatformPermission.ReportsView] },
            { key: 'pending_reconciliation', to: '/admin/pending-reconciliation', icon: Hourglass, permissions: [PlatformPermission.SettingsManage] },
            { key: 'bank_reconciliation', to: '/admin/settings/bank-reconciliation', icon: Banknote, permissions: [PlatformPermission.SettingsManage] },
        ],
    },
    {
        key: 'marketing',
        items: [
            { key: 'advertisers', to: '/admin/marketing/advertisers', icon: Megaphone, permissions: [PlatformPermission.MarketingAdvertisersManage] },
            { key: 'content_review', to: '/admin/marketing/content', icon: ClipboardCheck, permissions: [PlatformPermission.MarketingContentReview] },
            { key: 'sliders', to: '/admin/marketing/sliders', icon: Images, permissions: [PlatformPermission.MarketingSlidersManage] },
        ],
    },
    {
        key: 'admin_configuration',
        items: [
            { key: 'platform_team', to: '/admin/team', icon: Users, permissions: [PlatformPermission.PlatformUsersView] },
            { key: 'roles', to: '/admin/roles', icon: KeyRound, permissions: [PlatformPermission.RolesView] },
            { key: 'audit_log', to: '/admin/audit-log', icon: ShieldCheck, permissions: [PlatformPermission.AuditLogsView] },
            { key: 'settings', to: '/admin/settings', icon: Settings, permissions: [PlatformPermission.SettingsManage] },
            { key: 'business_activities', to: '/admin/settings/business-activities', icon: ClipboardList, permissions: [PlatformPermission.BusinessActivitiesManage] },
            { key: 'device_catalog', to: '/admin/settings/device-catalog', icon: MonitorSmartphone, permissions: [PlatformPermission.DeviceModelsManage] },
            { key: 'geography', to: '/admin/settings/geography', icon: MapPin, permissions: [PlatformPermission.SettingsManage] },
        ],
    },
];

// Hide a whole group (header included) when the user can't access any of its items.
const visibleGroups = computed(() =>
    navigationGroups
        .map((group) => ({ key: group.key, items: group.items.filter((item) => canAny(item.permissions)) }))
        .filter((group) => group.items.length > 0),
);

const userInitials = computed(() => {
    const name = authState.user?.name ?? 'Admin';

    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
});

// Logout is a native form POST submitted by the template's <form>
// element. Browser handles the boundary navigation — no XHR, no
// router push, no race condition.
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-950">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col border-e border-white/10 bg-slate-950 text-white transition-transform duration-300 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full'"
        >
            <div class="flex h-20 items-center justify-between px-5">
                <RouterLink to="/admin" class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-lg bg-teal-500 text-base font-black text-slate-950">
                        M
                    </span>
                    <span>
                        <span class="block text-sm font-semibold uppercase tracking-[0.18em] text-teal-300">
                            MITHQAL
                        </span>
                        <span class="block text-lg font-semibold">{{ t('app.name') }}</span>
                    </span>
                </RouterLink>

                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-slate-300 transition hover:bg-white/10 hover:text-white lg:hidden"
                    :aria-label="t('nav.sign_out')"
                    @click="sidebarOpen = false"
                >
                    <X class="size-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
                <!-- Dashboard (standalone) -->
                <RouterLink
                    :to="dashboardItem.to"
                    class="group flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold text-slate-300 transition duration-200 hover:bg-white/10 hover:text-white"
                    active-class="bg-white text-slate-950 shadow-lg shadow-black/20"
                    exact-active-class="bg-white text-slate-950 shadow-lg shadow-black/20"
                >
                    <component :is="dashboardItem.icon" class="size-5 transition duration-200 group-hover:scale-105" stroke-width="2" />
                    {{ t(`nav.${dashboardItem.key}`) }}
                </RouterLink>

                <!-- Grouped sections: Point of Sale · Marketing · Admin Configuration -->
                <div v-for="group in visibleGroups" :key="group.key" class="space-y-1">
                    <p class="px-3 pb-1 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">
                        {{ t(`nav_group.${group.key}`) }}
                    </p>
                    <RouterLink
                        v-for="item in group.items"
                        :key="item.key"
                        :to="item.to"
                        class="group flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold text-slate-300 transition duration-200 hover:bg-white/10 hover:text-white"
                        active-class="bg-white text-slate-950 shadow-lg shadow-black/20"
                        exact-active-class="bg-white text-slate-950 shadow-lg shadow-black/20"
                    >
                        <component
                            :is="item.icon"
                            class="size-5 transition duration-200 group-hover:scale-105"
                            stroke-width="2"
                        />
                        {{ t(`nav.${item.key}`) }}
                    </RouterLink>
                </div>
            </nav>

            <div class="m-4 rounded-xl border border-teal-300/20 bg-gradient-to-br from-teal-400/15 to-teal-500/5 p-4">
                <p class="text-sm font-semibold text-teal-100">MITHQAL Platform</p>
                <p class="mt-1 text-xs leading-5 text-slate-300">Central console for merchants, devices, and the charity round-up.</p>
            </div>
        </aside>

        <div class="lg:ps-72">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/85 backdrop-blur-xl">
                <div class="flex h-20 items-center gap-4 px-4 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="grid size-11 place-items-center rounded-lg border border-slate-200 text-slate-700 shadow-sm transition hover:bg-slate-50 lg:hidden"
                        :aria-label="t('nav.dashboard')"
                        @click="sidebarOpen = true"
                    >
                        <Menu class="size-5" />
                    </button>

                    <div class="hidden min-w-0 flex-1 items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-slate-500 md:flex">
                        <Search class="size-5 shrink-0" />
                        <input
                            type="search"
                            class="w-full bg-transparent text-sm font-medium outline-none placeholder:text-slate-400"
                            :placeholder="t('common.search_placeholder')"
                        >
                    </div>

                    <div class="ms-auto flex items-center gap-3">
                        <button
                            type="button"
                            class="relative grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50"
                            aria-label="Notifications"
                        >
                            <Bell class="size-5" />
                            <span class="absolute end-2 top-2 size-2 rounded-full bg-amber-500 ring-2 ring-white" />
                        </button>

                        <!-- User chip → Account Security (Phase D8: 2FA enrolment) -->
                        <RouterLink
                            to="/admin/security"
                            class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-2.5 py-2 shadow-sm transition hover:bg-slate-50"
                            :aria-label="t('security.title')"
                            :title="t('security.title')"
                        >
                            <span class="grid size-9 place-items-center rounded-lg bg-slate-950 text-sm font-semibold text-white">
                                {{ userInitials || 'AD' }}
                            </span>
                            <span class="hidden text-start sm:block">
                                <span class="block text-sm font-semibold text-slate-950">{{ authState.user?.name ?? 'Admin' }}</span>
                                <span class="block text-xs font-medium text-slate-500">{{ t('app.workspace') }}</span>
                            </span>
                            <ChevronDown class="hidden size-4 text-slate-400 sm:block" />
                        </RouterLink>

                        <form
                            method="POST"
                            action="/auth/logout"
                            class="inline-flex"
                            @submit="submitLogoutWithFreshCsrf"
                        >
                            <input type="hidden" name="_token" :value="csrfToken">
                            <button
                                type="submit"
                                :disabled="isLoggingOut"
                                class="grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-rose-50 hover:text-rose-700"
                                :class="{ 'cursor-wait opacity-70': isLoggingOut }"
                                :aria-label="t('nav.sign_out')"
                            >
                                <LogOut class="size-5" />
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="animate-dashboard px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>

        <FullscreenLoader :visible="isLoggingOut" :message="t('auth.signing_out')" />
    </div>
</template>

<style scoped>
.animate-dashboard {
    animation: dashboard-in 420ms ease-out both;
}

@keyframes dashboard-in {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
