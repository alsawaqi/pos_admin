import { computed, type ComputedRef } from 'vue';
import { authState, hasAnyRole, hasPermission, hasRole, isSuperAdmin } from '@/stores/auth';

export interface UsePermissions {
    can: (permission: string) => boolean;
    canAny: (permissions: readonly string[]) => boolean;
    canAll: (permissions: readonly string[]) => boolean;
    isRole: (role: string) => boolean;
    isAnyRole: (roles: readonly string[]) => boolean;
    isSuperAdmin: ComputedRef<boolean>;
    roles: ComputedRef<readonly string[]>;
    permissions: ComputedRef<readonly string[]>;
}

export function usePermissions(): UsePermissions {
    return {
        can: hasPermission,
        canAny: (permissions) =>
            permissions.length === 0
            || isSuperAdmin()
            || permissions.some((perm) => hasPermission(perm)),
        canAll: (permissions) => isSuperAdmin() || permissions.every((perm) => hasPermission(perm)),
        isRole: hasRole,
        isAnyRole: hasAnyRole,
        isSuperAdmin: computed(() => isSuperAdmin()),
        roles: computed(() => authState.user?.roles ?? []),
        permissions: computed(() => authState.user?.permissions ?? []),
    };
}
