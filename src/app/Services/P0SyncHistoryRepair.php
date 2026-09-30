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
            ->orderBy('id')->chunkById(500, function ($events): void {
                $rows = [];
                foreach ($events as $event) {
                    $rows[] = [
                        'sync_event_id' => $event->id, 'fingerprint' => self::fingerprint($event),
                        'ack_status' => $event->ack_status, 'result_json' => $event->result_json,
                        'source' => 'pre-migration', 'created_at' => now(),
                    ];
                }
                DB::table('pos_p0_sync_history')->insertOrIgnore($rows);
            });
    }

    /** Logical coalescing preserves the immutable administrative history. */
    private function intervals(iterable $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]->company_id === $row->company_id
                && $merged[$last]->branch_id === $row->branch_id
                && $merged[$last]->unassigned_at !== null
                && Carbon::parse($merged[$last]->unassigned_at)->lte(Carbon::parse($row->assigned_at))) {
                $merged[$last]->unassigned_at = $row->unassigned_at;
            } else {
                $merged[] = clone $row;
            }
        }
        return $merged;
    }

    private ?array $historyCache = null;
    private ?array $deviceCache = null;
    private ?array $branchCache = null;

    public function currentAssignment(object $device): ?object
    {
        $rows = $this->intervals(DB::table('pos_device_assignments_history')
            ->where('device_id', $device->id)->orderBy('assigned_at')->orderBy('id')->get());
        $open = array_values(array_filter($rows, fn ($r) => $r->unassigned_at === null
            && (int) $r->company_id === (int) $device->company_id
            && (int) $r->branch_id === (int) $device->branch_id));
        return count($open) === 1 ? $open[0] : null;
    }

    public function assignment(object $event, bool $unchanged = true): ?object
    {
        $rows = $this->historyCache[$event->device_id] ?? $this->intervals(
            DB::table('pos_device_assignments_history')->where('device_id', $event->device_id)
                ->orderBy('assigned_at')->orderBy('id')->get());
        $at = Carbon::parse($event->server_received_at);
        $matches = array_values(array_filter($rows, fn ($r) =>
            Carbon::parse($r->assigned_at)->lte($at)
            && ($r->unassigned_at === null || Carbon::parse($r->unassigned_at)->gte($at))));
        if (count($matches) !== 1) {
            return null;
        }
        $history = $matches[0];
        $branchCompany = $this->branchCache === null
            ? DB::table('pos_branches')->where('id', $history->branch_id)->value('company_id')
            : ($this->branchCache[$history->branch_id] ?? null);
        if ($history->company_id === null || $history->branch_id === null
            || (int) $branchCompany !== (int) $history->company_id) {
            return null;
        }
        if ($unchanged) {
            $device = $this->deviceCache === null
                ? DB::table('pos_devices')->find($event->device_id)
                : ($this->deviceCache[$event->device_id] ?? null);
            if ($device === null || $history->unassigned_at !== null
                || (int) $device->company_id !== (int) $history->company_id
                || (int) $device->branch_id !== (int) $history->branch_id) {
                return null;
            }
        }
        return $history;
    }

    public function repair(): array
    {
        $counts = ['attributed' => 0, 'restored' => 0, 'needs_review' => 0];
        $this->deviceCache = DB::table('pos_devices')->get()->keyBy('id')->all();
        $this->branchCache = DB::table('pos_branches')->pluck('company_id', 'id')->all();
        $this->historyCache = [];
        foreach (DB::table('pos_device_assignments_history')->orderBy('assigned_at')->orderBy('id')->get()->groupBy('device_id') as $id => $rows) {
            $this->historyCache[$id] = $this->intervals($rows);
        }
        // Cache absent histories too; no per-event fallback query.
        foreach ($this->deviceCache as $id => $_) {
            $this->historyCache[$id] ??= [];
        }
        try {
            DB::table('pos_sync_events')->where(fn ($q) => $q->whereNull('company_id')->orWhereNull('branch_id'))
                ->orderBy('id')->chunkById(500, function ($events) use (&$counts): void {
                    DB::transaction(function () use ($events, &$counts): void {
                        $rows = DB::table('pos_sync_events')->whereIn('id', $events->pluck('id'))
                            ->where(fn ($q) => $q->whereNull('company_id')->orWhereNull('branch_id'))->lockForUpdate()->get();
                        $originals = DB::table('pos_p0_sync_history')->whereIn('sync_event_id', $rows->pluck('id'))
                            ->get()->keyBy('sync_event_id');
                        $updates = [];
                        foreach ($rows as $row) {
                            $history = $this->assignment($row);
                            $original = $originals[$row->id] ?? null;
                            if ($history === null || ($row->ack_status === 'needs_review'
                                && ($original === null || ! hash_equals($original->fingerprint, self::fingerprint($row))))) {
                                $counts['needs_review']++;
                                continue;
                            }
                            $restore = $row->ack_status === 'needs_review';
                            $updates[$row->id] = [
                                'company_id' => $history->company_id, 'branch_id' => $history->branch_id,
                                'ack_status' => $restore ? $original->ack_status : $row->ack_status,
                                'result_json' => $restore ? $original->result_json : $row->result_json,
                            ];
                            $counts['attributed']++;
                            $counts['restored'] += (int) $restore;
                        }
                        if ($updates === []) {
                            return;
                        }
                        $sets = [];
                        $bindings = [];
                        foreach (['company_id', 'branch_id', 'ack_status', 'result_json'] as $column) {
                            $case = $column.' = CASE id';
                            foreach ($updates as $id => $values) {
                                $value = DB::getDriverName() === 'pgsql'
                                    ? match ($column) {
                                        'result_json' => 'CAST(? AS jsonb)',
                                        'company_id', 'branch_id' => 'CAST(? AS bigint)',
                                        default => 'CAST(? AS text)',
                                    } : '?';
                                $case .= ' WHEN '.(DB::getDriverName() === 'pgsql' ? 'CAST(? AS bigint)' : '?').' THEN '.$value;
                                array_push($bindings, $id, $values[$column]);
                            }
                            $sets[] = $case.' END';
                        }
                        $ids = array_keys($updates);
                        DB::update('UPDATE pos_sync_events SET '.implode(', ', $sets)
                            .' WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                            [...$bindings, ...$ids]);
                    });
                });
        } finally {
            $this->historyCache = $this->deviceCache = $this->branchCache = null;
        }
        return $counts;
    }
}
