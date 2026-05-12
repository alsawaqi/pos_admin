<?php

declare(strict_types=1);

namespace App\Actions\Security;

use App\Data\Security\AuditLogData;
use App\Models\AuditLog;

final class WriteAuditLogAction
{
    public function handle(AuditLogData $data): AuditLog
    {
        /** @var AuditLog $auditLog */
        $auditLog = AuditLog::query()->create([
            'actor_user_id' => $data->actorUserId,
            'company_id' => $data->companyId,
            'branch_id' => $data->branchId,
            'event' => $data->event,
            'auditable_type' => $data->auditableType,
            'auditable_id' => $data->auditableId,
            'ip_address' => $data->ipAddress,
            'user_agent' => $data->userAgent,
            'old_values' => $data->oldValues,
            'new_values' => $data->newValues,
            'metadata' => $data->metadata,
        ]);

        return $auditLog;
    }
}
