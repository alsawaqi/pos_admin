<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PlatformPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P-F7 — approve / reject body for the Pending Reconciliation queue.
 * ORDER-centric: the admin rules on the sale, the action resolves its
 * pending tenders.
 *
 * authorize() carries the settings.manage gate (same permission as the
 * bank reconciliation endpoints) so an unauthorised caller gets 403
 * BEFORE the order_ids are validated (validation would otherwise answer
 * 422 first and leak which ids exist).
 */
class PendingReconciliationDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(PlatformPermission::SettingsManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:pos_orders,id'],
        ];
    }
}
