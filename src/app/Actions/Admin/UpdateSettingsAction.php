<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bulk-update a set of platform settings.
 *
 * Accepts a flat key→value map (validated by the FormRequest
 * upstream). For each key:
 *   1. Look up the existing row (404s if the key isn't seeded —
 *      protects against typos creating ghost settings).
 *   2. Coerce the new value to the right shape for its `type`
 *      (e.g. integer fields → cast to int, multiselect → keep
 *      list).
 *   3. Persist via Setting::set() which busts the cache.
 *
 * Whole batch runs in a transaction so a half-applied update
 * (one valid + one invalid) rolls back entirely. The audit log
 * captures one event per changed key (not one per batch) so the
 * Audit Log viewer can show "support_email changed from X to Y"
 * directly.
 */
final readonly class UpdateSettingsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  key → new value
     * @return array<int, string>             keys that actually changed
     */
    public function handle(array $changes, User $actor): array
    {
        return DB::transaction(function () use ($changes, $actor): array {
            $changed = [];

            foreach ($changes as $key => $newValue) {
                /** @var Setting|null $row */
                $row = Setting::query()->where('key', $key)->first();
                if ($row === null) {
                    // Reject unknown keys outright — keeps the
                    // catalogue tight (no shadow keys piling up).
                    throw new RuntimeException("Unknown setting key: {$key}");
                }

                $coerced = $this->coerce($row->type, $newValue);
                $previous = Setting::get($key);

                // Skip a write when the value didn't actually
                // change — keeps the audit log uncluttered.
                if ($coerced === $previous) {
                    continue;
                }

                Setting::set($key, $coerced);
                $changed[] = $key;

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'setting.updated',
                    actorUserId: $actor->id,
                    auditableType: Setting::class,
                    auditableId: $row->id,
                    oldValues: ['key' => $key, 'value' => $previous],
                    newValues: ['key' => $key, 'value' => $coerced],
                ));
            }

            return $changed;
        });
    }

    /**
     * Cast the incoming value to the shape the type expects.
     * Frontend usually sends correct types already (number input
     * → number, checkbox → bool) but defensive coercion saves a
     * surprise when something hits the API from a script or curl.
     */
    private function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            Setting::TYPE_INTEGER => $value === null || $value === '' ? null : (int) $value,
            Setting::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            Setting::TYPE_MULTISELECT, Setting::TYPE_EMAIL_LIST => is_array($value)
                ? array_values(array_filter($value, static fn ($v): bool => $v !== null && $v !== ''))
                : [],
            Setting::TYPE_DATETIME => ($value === null || $value === '') ? null : (string) $value,
            // string / select / textarea — leave as-is, trim
            // surrounding whitespace.
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
