<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PlatformPermission;

/**
 * Canonical, UI-renderable view of the platform permission set.
 *
 * Mirrors pos_merchant's PermissionCatalog — every group +
 * permission gets an EN+AR label, the frontend consumes the
 * full tree through GET /admin/api/v1/roles/catalog and uses
 * it to render the role-editor's grouped checkbox grid.
 *
 * Why a separate Support class rather than enum methods:
 *   - The enum value string IS the contract; adding labels to
 *     the enum would bloat it and the cases would fight PHP's
 *     enum value typing.
 *   - Catalog metadata evolves (i18n changes, future blueprint
 *     reorganisations) independently of the permission lookup
 *     path. Keeping it separate lets us iterate without
 *     touching the security-critical part.
 */
final class PlatformPermissionCatalog
{
    /**
     * @return array<int, array{
     *     key: string,
     *     label_en: string,
     *     label_ar: string,
     *     permissions: list<array{key: string, label_en: string, label_ar: string}>,
     * }>
     */
    public static function platform(): array
    {
        return [
            [
                'key' => 'merchants',
                'label_en' => 'Merchants',
                'label_ar' => 'التجار',
                'permissions' => [
                    ['key' => PlatformPermission::MerchantsView->value, 'label_en' => 'See the merchants list', 'label_ar' => 'عرض قائمة التجار'],
                    ['key' => PlatformPermission::MerchantsCreate->value, 'label_en' => 'Onboard a new merchant', 'label_ar' => 'تسجيل تاجر جديد'],
                    ['key' => PlatformPermission::MerchantsUpdate->value, 'label_en' => 'Edit merchant details', 'label_ar' => 'تعديل بيانات التاجر'],
                    ['key' => PlatformPermission::MerchantsTransitionStatus->value, 'label_en' => 'Activate / suspend a merchant', 'label_ar' => 'تفعيل أو إيقاف تاجر'],
                    ['key' => PlatformPermission::MerchantsDelete->value, 'label_en' => 'Delete a merchant', 'label_ar' => 'حذف تاجر'],
                ],
            ],
            [
                'key' => 'merchant_documents',
                'label_en' => 'Merchant Documents',
                'label_ar' => 'وثائق التجار',
                'permissions' => [
                    ['key' => PlatformPermission::MerchantDocumentsView->value, 'label_en' => 'See uploaded documents', 'label_ar' => 'عرض المستندات المرفوعة'],
                    ['key' => PlatformPermission::MerchantDocumentsUpload->value, 'label_en' => 'Upload documents on behalf of a merchant', 'label_ar' => 'رفع المستندات نيابة عن التاجر'],
                    ['key' => PlatformPermission::MerchantDocumentsVerify->value, 'label_en' => 'Verify or reject documents', 'label_ar' => 'التحقق من المستندات أو رفضها'],
                ],
            ],
            [
                'key' => 'branches',
                'label_en' => 'Branches',
                'label_ar' => 'الفروع',
                'permissions' => [
                    ['key' => PlatformPermission::BranchesView->value, 'label_en' => 'See the branches list', 'label_ar' => 'عرض قائمة الفروع'],
                    ['key' => PlatformPermission::BranchesCreate->value, 'label_en' => 'Create a branch for a merchant', 'label_ar' => 'إنشاء فرع لتاجر'],
                    ['key' => PlatformPermission::BranchesUpdate->value, 'label_en' => 'Edit branch details', 'label_ar' => 'تعديل بيانات الفرع'],
                    ['key' => PlatformPermission::BranchesTransitionStatus->value, 'label_en' => 'Activate / deactivate a branch', 'label_ar' => 'تفعيل أو إيقاف فرع'],
                    ['key' => PlatformPermission::BranchesDelete->value, 'label_en' => 'Delete a branch', 'label_ar' => 'حذف فرع'],
                ],
            ],
            [
                'key' => 'devices',
                'label_en' => 'Devices',
                'label_ar' => 'الأجهزة',
                'permissions' => [
                    ['key' => PlatformPermission::DevicesView->value, 'label_en' => 'See the device fleet', 'label_ar' => 'عرض قائمة الأجهزة'],
                    ['key' => PlatformPermission::DevicesRegister->value, 'label_en' => 'Register a new device', 'label_ar' => 'تسجيل جهاز جديد'],
                    ['key' => PlatformPermission::DevicesAssign->value, 'label_en' => 'Assign a device to a branch', 'label_ar' => 'تعيين جهاز لفرع'],
                    ['key' => PlatformPermission::DevicesUnassign->value, 'label_en' => 'Unassign a device', 'label_ar' => 'إلغاء تعيين جهاز'],
                    ['key' => PlatformPermission::DevicesActivate->value, 'label_en' => 'Mint an activation code for a device', 'label_ar' => 'إصدار رمز تفعيل لجهاز'],
                    ['key' => PlatformPermission::DevicesDecommission->value, 'label_en' => 'Decommission a device', 'label_ar' => 'إخراج جهاز من الخدمة'],
                    ['key' => PlatformPermission::DeviceModelsManage->value, 'label_en' => 'Manage device makes + models catalog', 'label_ar' => 'إدارة كتالوج الماركات والموديلات'],
                    ['key' => PlatformPermission::DeviceShipmentsManage->value, 'label_en' => 'Manage device shipments', 'label_ar' => 'إدارة شحنات الأجهزة'],
                ],
            ],
            [
                'key' => 'merchant_users',
                'label_en' => 'Merchant Portal Users',
                'label_ar' => 'مستخدمو بوابة التاجر',
                'permissions' => [
                    ['key' => PlatformPermission::MerchantUsersView->value, 'label_en' => "See a merchant's portal users", 'label_ar' => 'عرض مستخدمي بوابة التاجر'],
                    ['key' => PlatformPermission::MerchantUsersInvite->value, 'label_en' => 'Create the initial merchant portal user', 'label_ar' => 'إنشاء أول مستخدم لبوابة التاجر'],
                    ['key' => PlatformPermission::MerchantUsersRevoke->value, 'label_en' => 'Suspend or reset a merchant portal user', 'label_ar' => 'إيقاف أو إعادة تعيين مستخدم بوابة التاجر'],
                ],
            ],
            [
                'key' => 'platform_users',
                'label_en' => 'Platform Team',
                'label_ar' => 'فريق المنصة',
                'permissions' => [
                    ['key' => PlatformPermission::PlatformUsersView->value, 'label_en' => 'See the platform team', 'label_ar' => 'عرض فريق المنصة'],
                    ['key' => PlatformPermission::PlatformUsersInvite->value, 'label_en' => 'Invite a new platform user', 'label_ar' => 'دعوة عضو جديد في المنصة'],
                    ['key' => PlatformPermission::PlatformUsersUpdateRoles->value, 'label_en' => "Change a platform user's roles", 'label_ar' => 'تغيير أدوار عضو في المنصة'],
                    ['key' => PlatformPermission::PlatformUsersSuspend->value, 'label_en' => 'Suspend or reactivate a platform user', 'label_ar' => 'إيقاف أو إعادة تفعيل عضو في المنصة'],
                ],
            ],
            [
                'key' => 'roles',
                'label_en' => 'Roles & Permissions',
                'label_ar' => 'الأدوار والصلاحيات',
                'permissions' => [
                    ['key' => PlatformPermission::RolesView->value, 'label_en' => 'See the role catalog + permission tree', 'label_ar' => 'عرض قائمة الأدوار وشجرة الصلاحيات'],
                    ['key' => PlatformPermission::RolesManage->value, 'label_en' => 'Create / edit / delete platform roles', 'label_ar' => 'إنشاء وتعديل وحذف أدوار المنصة'],
                ],
            ],
            [
                'key' => 'audit_logs',
                'label_en' => 'Audit Logs',
                'label_ar' => 'سجلات التدقيق',
                'permissions' => [
                    ['key' => PlatformPermission::AuditLogsView->value, 'label_en' => 'See the platform audit log', 'label_ar' => 'عرض سجل تدقيق المنصة'],
                ],
            ],
            [
                'key' => 'reports',
                'label_en' => 'Reports',
                'label_ar' => 'التقارير',
                'permissions' => [
                    ['key' => PlatformPermission::ReportsView->value, 'label_en' => 'See reports', 'label_ar' => 'عرض التقارير'],
                    ['key' => PlatformPermission::ReportsExport->value, 'label_en' => 'Export reports (CSV / Excel)', 'label_ar' => 'تصدير التقارير'],
                ],
            ],
            [
                'key' => 'settings',
                'label_en' => 'Settings',
                'label_ar' => 'الإعدادات',
                'permissions' => [
                    ['key' => PlatformPermission::SettingsManage->value, 'label_en' => 'Manage platform settings', 'label_ar' => 'إدارة إعدادات المنصة'],
                ],
            ],
            [
                'key' => 'business_activities',
                'label_en' => 'Business Activities',
                'label_ar' => 'الأنشطة التجارية',
                'permissions' => [
                    ['key' => PlatformPermission::BusinessActivitiesManage->value, 'label_en' => 'Manage business activity catalog', 'label_ar' => 'إدارة كتالوج الأنشطة التجارية'],
                ],
            ],
        ];
    }
}
