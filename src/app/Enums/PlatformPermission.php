<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformPermission: string
{
    case MerchantsView = 'merchants.view';
    case MerchantsCreate = 'merchants.create';
    case MerchantsUpdate = 'merchants.update';
    case MerchantsTransitionStatus = 'merchants.transition_status';
    case MerchantsDelete = 'merchants.delete';

    case MerchantDocumentsView = 'merchant_documents.view';
    case MerchantDocumentsUpload = 'merchant_documents.upload';
    case MerchantDocumentsVerify = 'merchant_documents.verify';

    case BranchesView = 'branches.view';
    case BranchesCreate = 'branches.create';
    case BranchesUpdate = 'branches.update';
    case BranchesTransitionStatus = 'branches.transition_status';

    case DevicesView = 'devices.view';
    case DevicesRegister = 'devices.register';
    case DevicesAssign = 'devices.assign';
    case DevicesUnassign = 'devices.unassign';
    case DevicesDecommission = 'devices.decommission';
    case DeviceModelsManage = 'device_models.manage';
    case DeviceShipmentsManage = 'device_shipments.manage';

    case MerchantUsersView = 'merchant_users.view';
    case MerchantUsersInvite = 'merchant_users.invite';
    case MerchantUsersRevoke = 'merchant_users.revoke';

    case PlatformUsersView = 'platform_users.view';
    case PlatformUsersInvite = 'platform_users.invite';
    case PlatformUsersUpdateRoles = 'platform_users.update_roles';
    case PlatformUsersSuspend = 'platform_users.suspend';

    case AuditLogsView = 'audit_logs.view';

    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    case SettingsManage = 'settings.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
