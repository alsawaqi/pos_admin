<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON projection of a {@see Device} for the admin portal.
 *
 * Used by both the list endpoint and the detail endpoint — the
 * difference is what relations were preloaded by the controller.
 * `whenLoaded()` / `whenCounted()` short-circuit fields when the data
 * hasn't been requested, so the list view stays lean even though the
 * detail view embeds nested company / branch summaries.
 *
 * Field shape mirrors what the Vue front-end's
 * `lib/api/devices.ts` expects — keep both files in lock-step or the
 * TypeScript types drift from reality.
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Identity surfaced to the front-end. id is internal,
            // uuid is the only one used in URLs.
            'id' => $this->id,
            'uuid' => $this->uuid,
            'serial_number' => $this->serial_number,
            'kiosk_id' => $this->kiosk_id,
            // Bank-issued permanent identifier — surfaces in the
            // Device Show overview and on the bank-reconciliation
            // queue payload.
            'terminal_id' => $this->terminal_id,
            // Commission profile binding. FK id is always present;
            // the nested object only when the controller preloaded
            // the relation (saves a join on the fleet list view).
            'commission_profile_id' => $this->commission_profile_id,
            'commission_profile' => $this->whenLoaded('commissionProfile', fn (): ?array => $this->commissionProfile ? [
                'id' => $this->commissionProfile->id,
                'name' => $this->commissionProfile->name,
                'is_active' => (bool) $this->commissionProfile->is_active,
            ] : null),

            // Acquiring bank binding. Same shape — FK id always,
            // nested object only when preloaded.
            'bank_id' => $this->bank_id,
            'bank' => $this->whenLoaded('bank', fn (): ?array => $this->bank ? [
                'id' => $this->bank->id,
                'name' => $this->bank->name,
                'short_name' => $this->bank->short_name,
                'swift_code' => $this->bank->swift_code,
                'is_active' => (bool) $this->bank->is_active,
            ] : null),

            // Display strings.
            'name' => $this->name,
            'label' => $this->label,

            // Catalogue FK ids — the front-end uses these to
            // preselect the cascading dropdowns when editing.
            'make_id' => $this->make_id,
            'model_id' => $this->model_id,
            // Embedded summaries, only sent when the controller
            // preloaded the relation. The list view eager-loads them
            // so the table can render "Sunmi / P2 Mini" without
            // following each FK individually (N+1).
            'make' => $this->whenLoaded('make', fn (): ?array => $this->make ? [
                'id' => $this->make->id,
                'name' => $this->make->name,
            ] : null),
            'model' => $this->whenLoaded('model', fn (): ?array => $this->model ? [
                'id' => $this->model->id,
                'name' => $this->model->name,
            ] : null),

            // Enums are serialised by their string value so the
            // front-end can compare against the same constants from
            // lib/api/devices.ts.
            'device_type' => $this->device_type?->value,
            'status' => $this->status?->value,

            // Current assignment summary (id-only payload — full
            // company + branch objects appear under `company` /
            // `branch` when preloaded).
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,

            // Embedded summaries, only sent when the controller
            // preloaded the relation. Keeps the list endpoint
            // payload small by avoiding 2 joins per row when the
            // table doesn't even render them.
            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->id,
                'uuid' => $this->company->uuid,
                'name' => $this->company->name,
                'name_ar' => $this->company->name_ar,
            ]),
            'branch' => $this->whenLoaded('branch', fn (): array => [
                'id' => $this->branch->id,
                'uuid' => $this->branch->uuid,
                'name' => $this->branch->name,
                'name_ar' => $this->branch->name_ar,
                'latitude' => $this->branch->latitude !== null ? (float) $this->branch->latitude : null,
                'longitude' => $this->branch->longitude !== null ? (float) $this->branch->longitude : null,
                'geofence_radius_m' => $this->branch->geofence_radius_m,
            ]),

            // Heartbeat snapshot — the GPS + battery the device last
            // reported. Drives the Admin dashboard's "low battery"
            // and "out of fence" alerts.
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_ip' => $this->last_ip,
            'last_lat' => $this->last_lat !== null ? (float) $this->last_lat : null,
            'last_lng' => $this->last_lng !== null ? (float) $this->last_lng : null,
            'last_battery' => $this->last_battery,
            'app_version' => $this->app_version,
            'firmware_version' => $this->firmware_version,

            // Assignment metadata.
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'registered_by_user_id' => $this->registered_by_user_id,
            'assigned_by_user_id' => $this->assigned_by_user_id,

            // Detail page only: full assignment history. Empty array
            // when the relation wasn't preloaded.
            'assignment_history' => $this->whenLoaded('assignmentHistory', fn (): array => $this->assignmentHistory
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'company_id' => $entry->company_id,
                    'branch_id' => $entry->branch_id,
                    'assigned_at' => $entry->assigned_at?->toIso8601String(),
                    'unassigned_at' => $entry->unassigned_at?->toIso8601String(),
                    'assigned_by_admin_id' => $entry->assigned_by_admin_id,
                    'unassign_reason' => $entry->unassign_reason,
                    'company' => $entry->relationLoaded('company') && $entry->company !== null ? [
                        'id' => $entry->company->id,
                        'name' => $entry->company->name,
                    ] : null,
                    'branch' => $entry->relationLoaded('branch') && $entry->branch !== null ? [
                        'id' => $entry->branch->id,
                        'name' => $entry->branch->name,
                    ] : null,
                ])
                ->all()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
