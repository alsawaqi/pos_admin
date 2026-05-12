import Dashboard from '@/Pages/Admin/Dashboard.vue';
import Login from '@/Pages/Auth/Login.vue';
import { authState, ensureAuthLoaded } from '@/stores/auth';
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';

declare module 'vue-router' {
    interface RouteMeta {
        guestOnly?: boolean;
        requiresAuth?: boolean;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        redirect: '/admin',
    },
    {
        path: '/login',
        name: 'login',
        component: Login,
        meta: { guestOnly: true },
    },
    {
        path: '/admin',
        name: 'admin.dashboard',
        component: Dashboard,
        meta: { requiresAuth: true },
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/admin',
    },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    await ensureAuthLoaded();

    if (to.meta.requiresAuth && !authState.user) {
        return {
            name: 'login',
            query: { redirect: to.fullPath },
        };
    }

    if (to.meta.guestOnly && authState.user) {
        return { name: 'admin.dashboard' };
    }

    return true;
});

router.afterEach((to) => {
    document.title = to.name === 'login'
        ? 'Login - MITHQAL POS Admin'
        : 'MITHQAL POS Admin';
});
