// Branches are managed inside the merchant view (Merchants/Show.vue) via
// the BranchFormModal — there is no standalone Branches section/routes.
import Dashboard from '@/Pages/Admin/Dashboard.vue';
import OrdersIndex from '@/Pages/Admin/Orders/Index.vue';
// P-F7 — Pending Reconciliation approval queue: orders whose force-recorded
// Soft POS tenders await the daily admin review. Approval fires the
// deferred money effects (commission split + charity round-up forwarding).
// settings.manage gated (sidebar + server).
import PendingReconciliationIndex from '@/Pages/Admin/PendingReconciliation/Index.vue';
// Platform Settlements (v2 #17) — per-merchant commission breakdown +
// platform totals over a date window. reports.view gated (sidebar +
// server). Mirrors the Orders/Sales page structure.
import SettlementsIndex from '@/Pages/Admin/Settlements/Index.vue';
// Round-Up Donations report — per-merchant charity round-up totals over a
// date window. reports.view gated (sidebar + server). Mirrors the
// Settlements page structure.
import RoundUpDonationsIndex from '@/Pages/Admin/RoundUpDonations/Index.vue';
// Sprint 1.2 — Devices section. The three pages mirror the Branches
// pattern (list → create → detail) so users have a consistent mental
// model. Register and Show handle the two-step lifecycle described
// in blueprint §6.1.
import DeviceList from '@/Pages/Admin/Devices/Index.vue';
import DeviceRegister from '@/Pages/Admin/Devices/Register.vue';
import DeviceEdit from '@/Pages/Admin/Devices/Edit.vue';
import DeviceShow from '@/Pages/Admin/Devices/Show.vue';
// Settings → Business Activities CRUD. Single-page UI with an
// inline modal for create/edit so admins can add new categories
// during pilots without leaving the page.
import BusinessActivities from '@/Pages/Admin/Settings/BusinessActivities/Index.vue';
// Settings → Device catalogue. Master/detail page for managing
// the makes (manufacturers) and the models they offer, which
// populate the cascading dropdowns on the Register Device page.
import DeviceCatalog from '@/Pages/Admin/Settings/DeviceCatalog/Index.vue';
import BankReconciliation from '@/Pages/Admin/Settings/BankReconciliation/Index.vue';
import Geography from '@/Pages/Admin/Settings/Geography/Index.vue';
// Sprint 1.5 — Admin Audit Log viewer (blueprint §4.7). Single
// page with filter strip + paginated table + before/after diff
// drawer + CSV export. Reads from pos_audit_logs.
import AuditLog from '@/Pages/Admin/AuditLog/Index.vue';
// Platform Team — admin user CRUD with invite/edit/suspend.
import PlatformTeam from '@/Pages/Admin/Team/Index.vue';
// Platform Roles & Permissions (Phase 4.8b) — role builder for
// platform admins.
import PlatformRoles from '@/Pages/Admin/Roles/Index.vue';
// Platform Settings — tabbed editor for the pos_settings catalogue.
import Settings from '@/Pages/Admin/Settings/Index.vue';
import MerchantCreate from '@/Pages/Admin/Merchants/Create.vue';
import MerchantList from '@/Pages/Admin/Merchants/Index.vue';
import MerchantShow from '@/Pages/Admin/Merchants/Show.vue';
import Login from '@/Pages/Auth/Login.vue';
// Phase D8 — TOTP 2FA: guest challenge page (login bounces here for
// enrolled accounts) + the authed Account Security page hosting the
// enrolment card.
import TwoFactorChallenge from '@/Pages/Auth/TwoFactorChallenge.vue';
import Security from '@/Pages/Admin/Security.vue';
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
        // Phase D8 — TOTP code step. The login POST parks the
        // pending state server-side and redirects here; the page
        // bounces back to /login when nothing is pending.
        path: '/two-factor-challenge',
        name: 'two-factor-challenge',
        component: TwoFactorChallenge,
        meta: { guestOnly: true },
    },
    {
        path: '/admin',
        name: 'admin.dashboard',
        component: Dashboard,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/orders',
        name: 'admin.orders.index',
        component: OrdersIndex,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/pending-reconciliation',
        name: 'admin.pending-reconciliation.index',
        component: PendingReconciliationIndex,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/settlements',
        name: 'admin.settlements.index',
        component: SettlementsIndex,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/roundup-donations',
        name: 'admin.roundup-donations.index',
        component: RoundUpDonationsIndex,
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
    // Branches: no standalone routes — managed via the BranchFormModal in
    // the merchant view (Merchants/Show.vue).
    // ---- Devices ---------------------------------------------------
    // The three pages map 1:1 to blueprint §4.4:
    //   /admin/devices          → fleet list
    //   /admin/devices/new      → Register Device form (step 1)
    //   /admin/devices/:uuid    → Device Detail (step 2 assign + history)
    {
        path: '/admin/devices',
        name: 'admin.devices.index',
        component: DeviceList,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/devices/new',
        name: 'admin.devices.create',
        component: DeviceRegister,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/devices/:uuid/edit',
        name: 'admin.devices.edit',
        component: DeviceEdit,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/devices/:uuid',
        name: 'admin.devices.show',
        component: DeviceShow,
        meta: { requiresAuth: true },
    },
    // ---- Settings: Business Activities -----------------------------
    {
        path: '/admin/settings/business-activities',
        name: 'admin.settings.business-activities',
        component: BusinessActivities,
        meta: { requiresAuth: true },
    },
    // ---- Settings: Device catalogue --------------------------------
    {
        path: '/admin/settings/device-catalog',
        name: 'admin.settings.device-catalog',
        component: DeviceCatalog,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/settings/geography',
        name: 'admin.settings.geography',
        component: Geography,
        meta: { requiresAuth: true },
    },
    {
        path: '/admin/settings/bank-reconciliation',
        name: 'admin.settings.bank-reconciliation',
        component: BankReconciliation,
        meta: { requiresAuth: true },
    },
    // ---- Audit Log viewer ------------------------------------------
    // Path matches the sidebar nav entry in AdminLayout.vue.
    {
        path: '/admin/audit-log',
        name: 'admin.audit-log.index',
        component: AuditLog,
        meta: { requiresAuth: true },
    },
    // ---- Platform Team ---------------------------------------------
    {
        path: '/admin/team',
        name: 'admin.team.index',
        component: PlatformTeam,
        meta: { requiresAuth: true },
    },
    // ---- Roles & Permissions (Phase 4.8b) --------------------------
    // Server enforces roles.view; SPA hides the nav entry for
    // users without it.
    {
        path: '/admin/roles',
        name: 'admin.roles.index',
        component: PlatformRoles,
        meta: { requiresAuth: true },
    },
    // ---- Platform Settings -----------------------------------------
    {
        path: '/admin/settings',
        name: 'admin.settings.index',
        component: Settings,
        meta: { requiresAuth: true },
    },
    // ---- Account Security (Phase D8) --------------------------------
    // Self-service 2FA enrolment for the signed-in admin. Reached
    // from the header user chip.
    {
        path: '/admin/security',
        name: 'admin.security',
        component: Security,
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
            // replace:true keeps the back-history from accumulating a
            // /admin entry that would just bounce back to /login again.
            return {
                name: 'login',
                query: { redirect: to.fullPath },
                replace: true,
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
            return { name: 'admin.dashboard', replace: true };
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
