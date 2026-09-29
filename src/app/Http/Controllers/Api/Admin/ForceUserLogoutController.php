<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ForceUserLogoutController extends Controller
{
    public function __invoke(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin()
            && $request->user()->hasRole(PlatformRole::SuperAdmin->value), 403);
        DB::transaction(function () use ($request, $user): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->forceFill(['auth_version' => (int) $user->auth_version + 1, 'remember_token' => null])->save();
            app(WriteAuditLogAction::class)->handle(new AuditLogData(
                event: 'user.force_logout', actorUserId: $request->user()->id,
                companyId: $user->company_id, auditableType: User::class, auditableId: $user->id,
            ));
        });

        return response()->json(['message' => 'All sessions have been revoked.']);
    }
}
