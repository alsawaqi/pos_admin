<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transformer for one row of the {@see AuditLog} table, formatted
 * for the admin Audit Log viewer (blueprint §4.7).
 *
 * The audit table stores the raw morph target FQCN in
 * `auditable_type` (e.g. `App\Models\Device`). We don't ship that
 * verbatim — the frontend asked for a short, stable enum value it
 * can render in a chip and use in the target-type filter dropdown
 * (`device`, `company`, `branch`, …). Keeping the
 * FQCN→short-name map server-side means the SPA never has to know
 * the PHP namespace layout.
 *
 * The actor / company / branch / auditable relations are surfaced
 * as nested objects when they were eager-loaded by the controller.
 * Each nested object stays small (the minimum needed to render a
 * chip + link in the table) so a page of 25 rows doesn't carry
 * hundreds of KB of related-model data.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * Stable short name → fully-qualified Eloquent class.
     *
     * Drives:
     *   - the resource output (FQCN → short name when serialising),
     *   - the controller's `target_type` filter input (short name →
     *     FQCN when querying),
     *   - the frontend's target-type dropdown options.
     *
     * Keeping the list in one place means a future morph target
     * just needs one line added here + a translation key.
     *
     * @var array<string, class-string>
     */
    public const TARGET_TYPE_MAP = [
        'company' => Company::class,
        'branch' => Branch::class,
        'device' => Device::class,
        'user' => User::class,
        // Note: company_document, business_activity, device_make,
        // and device_model are written as audit events too, but we
        // resolve their short name dynamically from the class
        // basename below — only models with a fixed shortcut need
        // an explicit map entry. The map is exposed primarily so
        // the controller can do reverse lookups for the filter.
    ];

    /**
     * Reverse of TARGET_TYPE_MAP — FQCN → short name, with a fallback
     * to the basename snake_cased for unmapped morph targets so they
     * still render in the viewer instead of leaking PHP class paths.
     *
     * Example fallback: `App\Models\CompanyDocument` → `company_document`.
     */
    private static function shortTargetType(?string $fqcn): ?string
    {
        if ($fqcn === null || $fqcn === '') {
            return null;
        }

        $reverse = array_flip(self::TARGET_TYPE_MAP);

        if (isset($reverse[$fqcn])) {
            return $reverse[$fqcn];
        }

        // Fallback: derive a snake_case short name from the class
        // basename. Keeps the viewer working for morph targets we
        // never added to the explicit map.
        $basename = class_basename($fqcn);

        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $basename));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'occurred_at' => optional($this->created_at)->toISOString(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,

            // The two JSON payloads drive the diff drawer. Always
            // returned (the drawer needs them on click); a typical
            // event payload is < 1 KB so this is not a problem at
            // page sizes of 25–100.
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'metadata' => $this->metadata,

            // Short, frontend-friendly target type name. Kept
            // alongside the bare id so the SPA can build a "view
            // target" link without hitting a separate lookup.
            'target_type' => self::shortTargetType($this->auditable_type),
            'target_id' => $this->auditable_id,

            // Compact actor object — only what the table cell needs.
            'actor' => $this->whenLoaded('actor', function () {
                /** @var User|null $actor */
                $actor = $this->actor;

                return $actor === null ? null : [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ];
            }),

            // Same compact shape for company + branch — these power
            // the "scoped to" column and feed the filter dropdowns
            // on first load (no extra round-trip).
            'company' => $this->whenLoaded('company', function () {
                /** @var Company|null $company */
                $company = $this->company;

                return $company === null ? null : [
                    'id' => $company->id,
                    'uuid' => $company->uuid,
                    'name' => $company->name,
                    'name_ar' => $company->name_ar,
                ];
            }),

            'branch' => $this->whenLoaded('branch', function () {
                /** @var Branch|null $branch */
                $branch = $this->branch;

                return $branch === null ? null : [
                    'id' => $branch->id,
                    'uuid' => $branch->uuid,
                    'name' => $branch->name,
                ];
            }),
        ];
    }
}
