<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\CompanyDocument;
use App\Models\User;

class CompanyDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::MerchantDocumentsView->value);
    }

    public function view(User $user, CompanyDocument $document): bool
    {
        return $user->can(PlatformPermission::MerchantDocumentsView->value);
    }

    public function upload(User $user): bool
    {
        return $user->can(PlatformPermission::MerchantDocumentsUpload->value);
    }

    public function verify(User $user, CompanyDocument $document): bool
    {
        return $user->can(PlatformPermission::MerchantDocumentsVerify->value);
    }

    public function reject(User $user, CompanyDocument $document): bool
    {
        return $user->can(PlatformPermission::MerchantDocumentsVerify->value);
    }

    public function delete(User $user, CompanyDocument $document): bool
    {
        return $user->can(PlatformPermission::MerchantDocumentsVerify->value);
    }
}
