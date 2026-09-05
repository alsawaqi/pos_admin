<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class BackfillQrTableSessions extends Command
{
    protected $signature = 'qr:backfill-table-sessions
        {--company= : pos_companies.id (required)}
        {--branch= : pos_branches.id (optional; must belong to --company)}
        {--apply : write; default is dry-run}
        {--include-orphans : also seat credential-less unpaid dine-in orders (D3); default off}
        {--chunk=200 : rows per chunk (1..1000)}
        {--max-session-id= : stable scan boundary; default MAX(pos_qr_sessions.id) at start}
        {--max-order-id= : stable boundary for orphans; default MAX(pos_orders.id) at start}';

    protected $description = 'Preview or attach legacy live dine-in QR tables to durable seatings';

    private const LIVE_CREDENTIALS = ['pending', 'active', 'ordered'];

    private const UNPAID_ORDERS = ['open', 'held', 'awaiting_payment'];

    private const LIVE_SEATINGS = ['open', 'billing', 'closing'];

    public function handle(): int
    {
        $companyId = $this->integerOption('company');
        $branchId = $this->option('branch') === null ? null : $this->integerOption('branch');
        $chunk = $this->integerOption('chunk');
        $maxSessionId = $this->option('max-session-id') === null
            ? (int) DB::table('pos_qr_sessions')->max('id') : $this->integerOption('max-session-id', true);
        $maxOrderId = $this->option('max-order-id') === null
            ? (int) DB::table('pos_orders')->max('id') : $this->integerOption('max-order-id', true);

        if ($companyId === null || ! DB::table('pos_companies')->where('id', $companyId)->exists()
            || ($this->option('branch') !== null && ($branchId === null || ! DB::table('pos_branches')
                ->where('id', $branchId)->where('company_id', $companyId)->exists()))
            || $chunk === null || $chunk > 1000 || $maxSessionId === null || $maxOrderId === null) {
            $this->error('Invalid options: require a known company, its branch, chunk 1..1000, and non-negative scan boundaries.');

            return self::INVALID;
        }

        $counts = ['scanned' => 0, 'created' => 0, 'linked_orders' => 0, 'linked_rounds' => 0,
            'allocated' => 0, 'skipped_attached' => 0, 'conflicts' => 0];
        $conflicts = ['table_has_live_seating' => 0, 'tenant_mismatch' => 0,
            'order_table_mismatch' => 0, 'table_missing' => 0];
        $apply = (bool) $this->option('apply');
        $previewTables = [];

        foreach ([false, true] as $orphan) {
            if ($orphan && ! $this->option('include-orphans')) {
                continue;
            }
            $query = $this->candidates($orphan, $companyId, $branchId, $orphan ? $maxOrderId : $maxSessionId);
            foreach ($query->lazyById($chunk) as $candidate) {
                $counts['scanned']++;
                // Restart a first-round race, never lock order after session.
                do {
                    $outcome = DB::transaction(fn (): array => $this->attach(
                        $candidate, $orphan, $companyId, $branchId,
                        $orphan ? $maxOrderId : $maxSessionId, $apply, $previewTables,
                    ));
                } while ($outcome['result'] === 'retry');

                if (isset($conflicts[$outcome['result']])) {
                    $counts['conflicts']++;
                    $conflicts[$outcome['result']]++;
                    $this->line('conflict:'.$outcome['result'].' session_id='.($orphan ? 'NULL' : $candidate->id)
                        .' order_id='.($outcome['order_id'] ?? ($orphan ? $candidate->id : 'NULL'))
                        .' table_id='.$candidate->table_id.' seating_id='.($outcome['seating_id'] ?? 'NULL'));
                } elseif ($outcome['result'] === 'skipped_attached') {
                    $counts['skipped_attached']++;
                } elseif ($outcome['result'] === 'created') {
                    if (! $apply) {
                        $previewTables[(int) $candidate->table_id] = true;
                    }
                    foreach (['created', 'linked_orders', 'linked_rounds', 'allocated'] as $key) {
                        $counts[$key] += $outcome[$key];
                    }
                }
            }
        }

        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = (($key === 'created' && ! $apply) ? 'would_create' : $key).'='.$count;
        }
        $codes = [];
        foreach ($conflicts as $code => $count) {
            $codes[] = $code.'='.$count;
        }
        $this->line(implode(' ', $parts).' ('.implode(' ', $codes).')');

        return $counts['conflicts'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function integerOption(string $name, bool $allowZero = false): ?int
    {
        $value = $this->option($name);
        if (! is_string($value) || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }
        $number = (int) $value;

        return $number >= ($allowZero ? 0 : 1) ? $number : null;
    }

    private function candidates(bool $orphan, int $companyId, ?int $branchId, int $maxId): Builder
    {
        $query = DB::table($orphan ? 'pos_orders' : 'pos_qr_sessions')
            ->where('company_id', $companyId)->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereNotNull('table_id')->whereNull('table_session_id')->where('id', '<=', $maxId);

        return $orphan
            ? $query->where('source', 'qr_web')->where('order_type', 'dine_in')->whereNull('qr_session_id')->whereIn('status', self::UNPAID_ORDERS)
            : $query->whereIn('status', self::LIVE_CREDENTIALS);
    }

    /** @return array<string, int|string> */
    private function attach(object $candidate, bool $orphan, int $companyId, ?int $branchId, int $maxId, bool $apply, array $previewTables): array
    {
        $table = DB::table('pos_tables')->where('id', $candidate->table_id)->lockForUpdate()->first();
        if ($table === null) {
            return ['result' => 'table_missing'];
        }
        $order = $orphan
            ? DB::table('pos_orders')->where('id', $candidate->id)->lockForUpdate()->first()
            : DB::table('pos_orders')->where('qr_session_id', $candidate->id)->orderByDesc('id')->lockForUpdate()->first();
        $session = $orphan ? null : DB::table('pos_qr_sessions')->where('id', $candidate->id)->lockForUpdate()->first();
        $row = $orphan ? $order : $session;
        if ($row === null) {
            return ['result' => 'stale'];
        }
        if ($row->table_session_id !== null || $order?->table_session_id !== null) {
            return ['result' => 'skipped_attached'];
        }
        if (! $this->candidates($orphan, $companyId, $branchId, $maxId)->where('id', $row->id)->exists()) {
            return ['result' => 'stale'];
        }
        if ((int) $row->table_id !== (int) $table->id) {
            $candidate->table_id = $row->table_id;

            return ['result' => 'retry'];
        }
        if (! $orphan) {
            $latestOrderId = DB::table('pos_orders')->where('qr_session_id', $row->id)->orderByDesc('id')->value('id');
            if ((int) $latestOrderId !== (int) ($order?->id)) {
                return ['result' => 'retry'];
            }
        }

        $floor = DB::table('pos_floors')->where('id', $table->floor_id)->first();
        if ((int) $table->company_id !== (int) $row->company_id) {
            return ['result' => 'tenant_mismatch', 'order_id' => $order?->id ?? 'NULL'];
        }
        foreach ([$row, $order] as $parent) {
            if ($parent !== null && ($floor === null || (int) $parent->company_id !== (int) $floor->company_id
                || (int) $parent->branch_id !== (int) $floor->branch_id)) {
                return ['result' => 'tenant_mismatch', 'order_id' => $order?->id ?? 'NULL'];
            }
        }
        if ($order !== null && (int) $order->table_id !== (int) $table->id) {
            return ['result' => 'order_table_mismatch', 'order_id' => $order->id];
        }
        $seating = DB::table('pos_table_sessions')->where('company_id', $row->company_id)
            ->where('branch_id', $row->branch_id)->where('table_id', $table->id)
            ->whereIn('status', self::LIVE_SEATINGS)->lockForUpdate()->first();
        if ($seating !== null) {
            return ['result' => 'table_has_live_seating', 'order_id' => $order?->id ?? 'NULL', 'seating_id' => $seating->id];
        }
        // Preview reserves a table just as an earlier inserted seating does
        // on apply, without allocating a counter or inventing a database id.
        if (isset($previewTables[(int) $table->id])) {
            return ['result' => 'table_has_live_seating', 'order_id' => $order?->id ?? 'NULL', 'seating_id' => 'would_create'];
        }

        $rounds = $session === null ? null : DB::table('pos_qr_order_rounds')->where('qr_session_id', $session->id)->whereNull('table_session_id');
        $result = ['result' => 'created', 'created' => 1,
            'linked_orders' => (int) ($order !== null && $order->table_session_id === null),
            'linked_rounds' => $rounds?->count() ?? 0, 'allocated' => (int) ($order === null)];
        if (! $apply) {
            return $result;
        }

        $now = Carbon::now('UTC');
        $billing = $order !== null && in_array($order->status, ['held', 'awaiting_payment'], true);
        // Orphans retain opened_at, deriving the horizon with the existing
        // API setting and its six-hour default (admin has no qr config).
        $expiresAt = $session?->expires_at ?? Carbon::parse($order->opened_at, 'UTC')
            ->addHours(max(1, (int) config('qr.dine_in_session_lifetime_hours', 6)));
        $seatingId = DB::table('pos_table_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $row->company_id, 'branch_id' => $row->branch_id,
            'table_id' => $table->id, 'status' => $billing ? 'billing' : 'open', 'origin' => 'station',
            'opened_by_device_id' => $session?->device_id, 'order_id' => $order?->id,
            'opened_at' => $session?->created_at ?? $order->opened_at, 'expires_at' => $expiresAt,
            'billing_at' => $billing ? $order->updated_at : null,
            'temp_reference' => $order?->temp_reference, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($order === null) {
            DB::table('pos_table_sessions')->where('id', $seatingId)->where('company_id', $row->company_id)
                ->where('branch_id', $row->branch_id)->whereIn('status', ['open', 'billing'])->update([
                    'temp_reference' => $this->allocateTempReference((int) $row->company_id, (int) $row->branch_id),
                ]);
        }
        if ($session !== null) {
            DB::table('pos_qr_sessions')->where('id', $session->id)->where('company_id', $row->company_id)
                ->where('branch_id', $row->branch_id)->whereNull('table_session_id')->update(['table_session_id' => $seatingId]);
            $rounds->update(['table_session_id' => $seatingId]);
        }
        if ($order !== null) {
            DB::table('pos_orders')->where('id', $order->id)->where('company_id', $row->company_id)
                ->where('branch_id', $row->branch_id)->whereNull('table_session_id')->update(['table_session_id' => $seatingId]);
        }

        return $result;
    }

    // T1 allocator body, with only its clock made explicitly UTC for admin.
    private function allocateTempReference(int $companyId, int $branchId): string
    {
        $today = Carbon::now('UTC');
        $date = $today->toDateString();

        $number = DB::transaction(function () use ($companyId, $branchId, $today, $date): int {
            DB::table('pos_temp_reference_sequences')->insertOrIgnore([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'seq_date' => $date,
                'next_number' => 1,
                'created_at' => $today,
                'updated_at' => $today,
            ]);

            $sequence = DB::table('pos_temp_reference_sequences')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('seq_date', $date)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new RuntimeException('Temporary reference sequence was not found.');
            }

            $number = (int) $sequence->next_number;
            DB::table('pos_temp_reference_sequences')
                ->where('id', (int) $sequence->id)
                ->update([
                    'next_number' => $number + 1,
                    'updated_at' => $today,
                ]);

            return $number;
        });

        return 'T-'.$today->format('md').'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
