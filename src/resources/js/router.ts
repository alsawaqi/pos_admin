import Dashboard from '@/Pages/Admin/Dashboard.vue';
import MerchantCreate from '@/Pages/Admin/Merchants/Create.vue';
import MerchantList from '@/Pages/Admin/Merchants/Index.vue';
import MerchantShow from '@/Pages/Admin/Merchants/Show.vue';
import Login from '@/Pages/Auth/Login.vue';
import { authState, ensureAuthLoaded, resetAuthBootPromise } from '@/stores/auth';
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
        path: '/admin/merchants',
        name: 'admin.merchants.index',
        component: MerchantList,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/merchants/new',
        name: 'admin.merchants.create',
        component: MerchantCreate,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/merchants/:uuid',
        name: 'admin.merchants.show',
        component: MerchantShow,
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
    if (to.meta.requiresAuth) {
        if (!authState.loaded || !authState.user) {
            resetAuthBootPromise();
            await ensureAuthLoaded();
        }

        if (!authState.user) {
            return {
                name: 'login',
                query: { redirect: to.fullPath },
            };
        }
    }

    if (to.meta.guestOnly) {
        // Do not probe /auth/user from the login page. A guest auth-check XHR
        // can finish after a native login POST and overwrite the fresh session
        // cookie, producing the intermittent "login only works after refresh"
        // flow. Direct browser visits are already protected by Laravel's
        // guest middleware, and in-SPA authenticated navigations still use the
        // current authState to bounce away from /login.
        if (authState.user) {
            return { name: 'admin.dashboard' };
        }

        return true;
    }

    return true;
});

router.afterEach((to) => {
    document.title = to.name === 'login'
        ? 'Login - MITHQAL POS Admin'
        : 'MITHQAL POS Admin';
});
