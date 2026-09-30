<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\P0SyncHistoryRepair;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class RepairP0SyncHistory extends Command
{
    protected $signature = 'pos:repair-sync-history
        {operation=list : list, import, repair, attribute, replay}
        {--file= : JSON-lines backup for import; original device snapshot JSON for attribute}
        {--event= : Sync event numeric ID}
        {--actor= : Platform Super Admin user ID for mutations}
        {--reason= : Required evidence/reference for the audit trail}';

    protected $description = 'Review historical sync receipts without guessing their original tenant or result';

    public function handle(P0SyncHistoryRepair $repair): int
    {
        try {
            $op = $this->argument('operation');
            if ($op === 'list') {
                foreach (DB::table('pos_sync_events')->where('ack_status', 'needs_review')->orderBy('id')->cursor() as $row) {
                    // A sale refused at ingest was stored at push time: its
                    // evidence is when it was made (or its identity tag),
                    // never the merchant the device belongs to today.
                    $refused = P0SyncHistoryRepair::refusedAtIngest($row);
                    $this->line(json_encode(['id' => $row->id, 'device_id' => $row->device_id,
                        'event_type' => $row->event_type, 'server_received_at' => $row->server_received_at,
                        'refused_at_ingest' => $refused,
                        'client_timestamp' => $row->client_timestamp,
                        'claimed_identity' => P0SyncHistoryRepair::claimedIdentity($row),
                        'assignment_at_evidence_time' => $repair->assignment($row, false)?->id,
                        'continuous_assignment' => $refused ? null : $repair->assignment($row)?->id,
                        'saved_result' => DB::table('pos_p0_sync_history')->where('sync_event_id', $row->id)->exists()]));
                }

                return self::SUCCESS;
            }
            $actor = User::find($this->option('actor'));
            if (! $actor?->isPlatformAdmin() || ! $actor->hasRole(PlatformRole::SuperAdmin->value)
                || mb_strlen(trim((string) $this->option('reason'))) < 3) {
                $this->error('A Platform Super Admin and a written evidence reference are required.');

                return self::FAILURE;
            }
            $result = DB::transaction(function () use ($op, $repair, $actor) {
                $result = match ($op) {
                    'import' => $this->importBackup(),
                    'repair' => $repair->repair(),
                    'attribute' => $this->attribute($repair, $actor),
                    'replay' => $this->queueReplay(),
                    default => throw new \InvalidArgumentException('Unknown operation.'),
                };
                app(WriteAuditLogAction::class)->handle(new AuditLogData(
                    event: 'sync.history.'.$op, actorUserId: $actor->id,
                    metadata: ['reason' => trim($this->option('reason')), 'result' => $result],
                ));

                return $result;
            });
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof QueryException ? 'Database operation failed; no partial repair was committed.' : $e->getMessage());

            return self::FAILURE;
        }
    }

    private function importBackup(): array
    {
        $file = fopen((string) $this->option('file'), 'rb');
        if ($file === false) {
            throw new \RuntimeException('Cannot read the backup export.');
        }
        $count = 0;
        try {
            while (($line = fgets($file)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $saved = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
                $row = DB::table('pos_sync_events')->where('id', $saved->id)->lockForUpdate()->first();
                if ($row === null) {
                    continue;
                }
                if (is_object($saved->payload_json) || is_array($saved->payload_json)) {
                    $saved->payload_json = json_encode($saved->payload_json);
                }
                if (! in_array($saved->ack_status, ['received', 'failed', 'processed'], true)
                    || ! hash_equals(P0SyncHistoryRepair::fingerprint($row), P0SyncHistoryRepair::fingerprint($saved))) {
                    throw new \RuntimeException('Backup receipt fingerprint/status mismatch at event '.$row->id);
                }
                $originalResult = $saved->result_json === null ? null
                    : (is_string($saved->result_json) ? $saved->result_json : json_encode($saved->result_json));
                $count += DB::table('pos_p0_sync_history')->insertOrIgnore([
                    'sync_event_id' => $row->id, 'fingerprint' => P0SyncHistoryRepair::fingerprint($row),
                    'ack_status' => $saved->ack_status, 'result_json' => $originalResult,
                    'source' => 'verified-backup', 'created_at' => now(),
                ]);
            }
        } finally {
            fclose($file);
        }

        return ['imported' => $count];
    }

    private function attribute(P0SyncHistoryRepair $repair, User $actor): array
    {
        $row = DB::table('pos_sync_events')->where('id', $this->option('event'))->lockForUpdate()->first();
        if ($row === null || $row->ack_status === 'processed' || $row->device_id === null) {
            throw new \RuntimeException('An existing unprocessed device receipt is required.');
        }
        $snapshot = json_decode(file_get_contents((string) $this->option('file')), true, 512, JSON_THROW_ON_ERROR);
        Validator::make($snapshot, [
            'company_id' => ['required', 'integer', 'exists:pos_companies,id'],
            'branch_id' => ['required', 'integer', 'exists:pos_branches,id'],
            'bank_id' => ['present', 'nullable', 'integer', 'exists:banks,id'],
            'terminal_id' => ['present', 'nullable', 'string', 'max:64'],
            'commission_profile_id' => ['present', 'nullable', 'integer'],
            'organization_id' => ['present', 'nullable', 'integer'],
            'device_type' => ['required', 'in:pos_terminal,payment_station,handheld,fixed_pos'],
            'softpos_profile' => ['sometimes', 'array:provider,package'],
            'softpos_profile.provider' => ['nullable', 'string', 'max:32'],
            'softpos_profile.package' => ['nullable', 'string', 'max:255'],
        ])->validate();
        if (! DB::table('pos_branches')->where('id', $snapshot['branch_id'])->where('company_id', $snapshot['company_id'])->exists()) {
            throw new \RuntimeException('Branch does not belong to the original company.');
        }
        // A tag the device stamped on the sale is the strongest evidence; an
        // untagged sale is checked against the assignment in force when it was
        // made (refused-at-ingest rows) or received (historical rows).
        $claimed = P0SyncHistoryRepair::claimedIdentity($row);
        if ($claimed !== null) {
            if ((int) $claimed['company_id'] !== (int) $snapshot['company_id']
                || (int) $claimed['branch_id'] !== (int) $snapshot['branch_id']) {
                throw new \RuntimeException('The proposed identity contradicts the identity the device stamped on this sale.');
            }
        } else {
            $history = $repair->assignment($row, false);
            if ($history !== null && ((int) $history->company_id !== (int) $snapshot['company_id']
                || (int) $history->branch_id !== (int) $snapshot['branch_id'])) {
                throw new \RuntimeException('The proposed identity contradicts assignment history.');
            }
        }
        if ($row->company_id !== null && ((int) $row->company_id !== (int) $snapshot['company_id']
            || (int) $row->branch_id !== (int) $snapshot['branch_id'])) {
            throw new \RuntimeException('The proposed identity contradicts the stamped receipt.');
        }
        $snapshot = array_intersect_key($snapshot, array_flip(['company_id', 'branch_id', 'bank_id', 'terminal_id',
            'commission_profile_id', 'organization_id', 'device_type', 'softpos_profile']));
        $existing = DB::table('pos_sync_event_reviews')->where('sync_event_id', $row->id)->first();
        if ($existing !== null) {
            throw new \RuntimeException('This receipt already has an immutable attribution review.');
        }
        $id = DB::table('pos_sync_event_reviews')->insertGetId([
            'sync_event_id' => $row->id, 'actor_user_id' => $actor->id,
            'company_id' => $snapshot['company_id'], 'branch_id' => $snapshot['branch_id'],
            'fingerprint' => P0SyncHistoryRepair::fingerprint($row),
            'reason' => trim($this->option('reason')), 'device_snapshot' => json_encode($snapshot),
            'status' => 'attributed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_sync_events')->where('id', $row->id)->update([
            'company_id' => $snapshot['company_id'], 'branch_id' => $snapshot['branch_id'],
            'ack_status' => 'needs_review',
        ]);

        return ['review_id' => $id, 'event_id' => $row->id];
    }

    private function queueReplay(): array
    {
        $review = DB::table('pos_sync_event_reviews')->where('sync_event_id', $this->option('event'))->lockForUpdate()->first();
        if ($review === null || ! in_array($review->status, ['attributed', 'failed'], true)) {
            throw new \RuntimeException('Attribute the original identity before requesting a replay.');
        }
        DB::table('pos_sync_event_reviews')->where('id', $review->id)->update(['status' => 'queued', 'updated_at' => now()]);

        return ['review_id' => $review->id, 'next' => 'Run in pos_api: php artisan sync:replay-reviewed --review='.$review->id];
    }
}
