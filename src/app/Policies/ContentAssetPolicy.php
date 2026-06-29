<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\ContentAsset;
use App\Models\User;

/**
 * Authorisation for reviewing advertiser content. The whole surface (browse the
 * review queue + approve / reject) requires marketing.content.review. Super
 * admins pass via the Gate::before short-circuit in AuthServiceProvider.
 */
class ContentAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::MarketingContentReview->value);
    }

    public function review(User $user, ContentAsset $asset): bool
    {
        return $user->can(PlatformPermission::MarketingContentReview->value);
    }
}
