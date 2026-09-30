<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** A receipt identity is evidence, never an inference from today's merchant alone. */
final class P0SyncHistoryRepair
{
    public static function fingerprint(object $event): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode([
            (string) $event->client_event_id, (int) $event->device_id, $event->event_type,
            Carbon::parse($event->server_received_at)->utc()->format('Y-m-d H:i:s.u'),
            $canonical(json_decode($event->payload_json, true, 512, JSON_THROW_ON_ERROR)),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function preserve(): void
    {
        DB::table('pos_sync_events')->whereIn('ack_status', ['received', 'failed', 'processed'])
            ->orderBy('id')->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    DB::table('pos_p0_sync_history')->insertOrIgnore([
                        'sync_event_id' => $event->id, 'fingerprint' => self::fingerprint($event),
                        'ack_status' => $event->ack_status, 'result_json' => $event->result_json,
                        'source' => 'pre-migration', 'created_at' => now(),
                    ]);
                }
            });
    }

    public function assignment(object $event, bool $unchanged = true): ?object
    {
        $matches = DB::table('pos_device_assignments_history')
            ->where('device_id', $event->device_id)->where('assigned_at', '<=', $event->server_received_at)
            ->where(fn ($q) => $q->whereNull('unassigned_at')->orWhere('unassigned_at', '>=', $event->server_received_at))
            ->get();
        if ($matches->count() !== 1) {
            return null;
        }
        $history = $matches->first();
        if ($history->company_id === null || $history->branch_id === null
            || ! DB::table('pos_branches')->where('id', $history->branch_id)->where('company_id', $history->company_id)->exists()) {
            return null;
        }
        if ($unchanged) {
            $device = DB::table('pos_devices')->find($event->device_id);
            if ($device === null || $history->unassigned_at !== null
                || (int) $device->company_id !== (int) $history->company_id
                || (int) $device->branch_id !== (int) $history->branch_id
                || ($device->assigned_at !== null && Carbon::parse($device->assigned_at)->gt(Carbon::parse($event->server_received_at)))
                || DB::table('pos_device_assignments_history')->where('device_id', $event->device_id)
                    ->where('id', '<>', $history->id)->where('assigned_at', '>=', $event->server_received_at)->exists()) {
                return null;
            }
        }

        return $history;
    }

    public function repair(): array
    {
        $counts = ['attributed' => 0, 'restored' => 0, 'needs_review' => 0];
        DB::table('pos_sync_events')->where(fn ($q) => $q->whereNull('company_id')->orWhereNull('branch_id'))
            ->orderBy('id')->chunkById(200, function ($events) use (&$counts): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event, &$counts): void {
                        $row = DB::table('pos_sync_events')->where('id', $event->id)->lockForUpdate()->first();
                        $history = $this->assignment($row);
                        if ($history === null) {
                            $counts['needs_review']++;

                            return;
                        }
                        $original = DB::table('pos_p0_sync_history')->where('sync_event_id', $row->id)->first();
                        // The first P0 release destroyed old ACK results. A backup is required
                        // on an already-migrated database; do not invent its former status.
                        if ($row->ack_status === 'needs_review'
                            && ($original === null || ! hash_equals($original->fingerprint, self::fingerprint($row)))) {
                            $counts['needs_review']++;

                            return;
                        }
                        $values = ['company_id' => $history->company_id, 'branch_id' => $history->branch_id];
                        if ($row->ack_status === 'needs_review') {
                            $values += ['ack_status' => $original->ack_status, 'result_json' => $original->result_json];
                            $counts['restored']++;
                        }
                        DB::table('pos_sync_events')->where('id', $row->id)->update($values);
                        $counts['attributed']++;
                    });
                }
            });

        return $counts;
    }
}
