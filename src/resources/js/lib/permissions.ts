/**
 * Mirror of {@link \App\Enums\PlatformPermission}. Keep in sync — referenced
 * by the sidebar and route guards so the UI only surfaces what the user can
 * actually act on. The server is still the source of truth.
 */
export const PlatformPermission = {
    MerchantsView: 'merchants.view',
    MerchantsCreate: 'merchants.create',
    MerchantsUpdate: 'merchants.update',
    MerchantsTransitionStatus: 'merchants.transition_status',
    MerchantsDelete: 'merchants.delete',

    MerchantDocumentsView: 'merchant_documents.view',
    MerchantDocumentsUpload: 'merchant_documents.upload',
    MerchantDocumentsVerify: 'merchant_documents.verify',

    BranchesView: 'branches.view',
    BranchesCreate: 'branches.create',
    BranchesUpdate: 'branches.update',
    BranchesTransitionStatus: 'branches.transition_status',

    DevicesView: 'devices.view',
    DevicesRegister: 'devices.register',
    DevicesAssign: 'devices.assign',
    DevicesUnassign: 'devices.unassign',
    DevicesDecommission: 'devices.decommission',
    DeviceModelsManage: 'device_models.manage',
    DeviceShipmentsManage: 'device_shipments.manage',

    MerchantUsersView: 'merchant_users.view',
    MerchantUsersInvite: 'merchant_users.invite',
    MerchantUsersRevoke: 'merchant_users.revoke',

    PlatformUsersView: 'platform_users.view',
    PlatformUsersInvite: 'platform_users.invite',
    PlatformUsersUpdateRoles: 'platform_users.update_roles',
    PlatformUsersSuspend: 'platform_users.suspend',

    AuditLogsView: 'audit_logs.view',

    ReportsView: 'reports.view',
    ReportsExport: 'reports.export',

    SettingsManage: 'settings.manage',
} as const;

export type PlatformPermissionValue = (typeof PlatformPermission)[keyof typeof PlatformPermission];

export const PlatformRole = {
    SuperAdmin: 'platform_super_admin',
    OnboardingOfficer: 'onboarding_officer',
    DeviceOperations: 'device_operations',
    Support: 'support',
    FinanceViewer: 'finance_viewer',
} as const;

export type PlatformRoleValue = (typeof PlatformRole)[keyof typeof PlatformRole];
