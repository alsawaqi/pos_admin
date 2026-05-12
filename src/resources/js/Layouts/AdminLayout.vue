<script setup lang="ts">
import {
    Bell,
    Building2,
    ChevronDown,
    ClipboardList,
    Gauge,
    LogOut,
    Menu,
    MonitorSmartphone,
    Search,
    Settings,
    ShieldCheck,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { authState, logout } from '@/stores/auth';

const sidebarOpen = ref(false);
const router = useRouter();

const navigation = [
    { label: 'Dashboard', to: '/admin', icon: Gauge, active: true },
    { label: 'Companies', to: '/admin#companies', icon: Building2, active: false },
    { label: 'Branches', to: '/admin#branches', icon: ClipboardList, active: false },
    { label: 'Devices', to: '/admin#devices', icon: MonitorSmartphone, active: false },
    { label: 'Admin Users', to: '/admin#users', icon: Users, active: false },
    { label: 'Security', to: '/admin#security', icon: ShieldCheck, active: false },
    { label: 'Settings', to: '/admin#settings', icon: Settings, active: false },
] as const;

const userInitials = computed(() => {
    const name = authState.user?.name ?? 'Admin';

    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
});

async function signOut(): Promise<void> {
    await logout();
    await router.replace('/login');
}
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-950">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-white/10 bg-slate-950 text-white transition-transform duration-300 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
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
                        <span class="block text-lg font-semibold">POS Admin</span>
                    </span>
                </RouterLink>

                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-lg text-slate-300 transition hover:bg-white/10 hover:text-white lg:hidden"
                    aria-label="Close navigation"
                    @click="sidebarOpen = false"
                >
                    <X class="size-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <RouterLink
                    v-for="item in navigation"
                    :key="item.label"
                    :to="item.to"
                    class="group flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold transition duration-200"
                    :class="
                        item.active
                            ? 'bg-white text-slate-950 shadow-lg shadow-black/20'
                            : 'text-slate-300 hover:bg-white/10 hover:text-white'
                    "
                >
                    <component
                        :is="item.icon"
                        class="size-5 transition duration-200 group-hover:scale-105"
                        stroke-width="2"
                    />
                    {{ item.label }}
                </RouterLink>
            </nav>

            <div class="m-4 rounded-lg border border-teal-300/20 bg-teal-400/10 p-4">
                <p class="text-sm font-semibold text-teal-200">Pilot readiness</p>
                <div class="mt-3 h-2 rounded-full bg-white/10">
                    <div class="h-2 w-2/3 rounded-full bg-teal-300" />
                </div>
                <p class="mt-3 text-xs leading-5 text-slate-300">Foundation, devices, and merchant onboarding are being prepared.</p>
            </div>
        </aside>

        <div class="lg:pl-72">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/85 backdrop-blur-xl">
                <div class="flex h-20 items-center gap-4 px-4 sm:px-6 lg:px-8">
                    <button
                        type="button"
                        class="grid size-11 place-items-center rounded-lg border border-slate-200 text-slate-700 shadow-sm transition hover:bg-slate-50 lg:hidden"
                        aria-label="Open navigation"
                        @click="sidebarOpen = true"
                    >
                        <Menu class="size-5" />
                    </button>

                    <div class="hidden min-w-0 flex-1 items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-slate-500 md:flex">
                        <Search class="size-5 shrink-0" />
                        <input
                            type="search"
                            class="w-full bg-transparent text-sm font-medium outline-none placeholder:text-slate-400"
                            placeholder="Search merchants, branches, devices..."
                        >
                    </div>

                    <div class="ml-auto flex items-center gap-3">
                        <button
                            type="button"
                            class="relative grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50"
                            aria-label="Notifications"
                        >
                            <Bell class="size-5" />
                            <span class="absolute right-2 top-2 size-2 rounded-full bg-amber-500 ring-2 ring-white" />
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-2.5 py-2 shadow-sm transition hover:bg-slate-50"
                        >
                            <span class="grid size-9 place-items-center rounded-lg bg-slate-950 text-sm font-semibold text-white">
                                {{ userInitials || 'AD' }}
                            </span>
                            <span class="hidden text-left sm:block">
                                <span class="block text-sm font-semibold text-slate-950">{{ authState.user?.name ?? 'Admin' }}</span>
                                <span class="block text-xs font-medium text-slate-500">Platform workspace</span>
                            </span>
                            <ChevronDown class="hidden size-4 text-slate-400 sm:block" />
                        </button>

                        <button
                            type="button"
                            class="grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:bg-rose-50 hover:text-rose-700"
                            aria-label="Sign out"
                            @click="signOut"
                        >
                            <LogOut class="size-5" />
                        </button>
                    </div>
                </div>
            </header>

            <main class="animate-dashboard px-4 py-6 sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
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
