<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TenantIntegrityChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CheckTenantIntegrity extends Command
{
    protected $signature = 'pos:check-tenant-integrity';

    protected $description = 'Read-only tenant relation checks; persist counts and sample ids for platform admins.';

    public function handle(TenantIntegrityChecks $checks): int
    {
        $id = DB::table('pos_tenant_integrity_runs')->insertGetId(['started_at' => now(), 'status' => 'running']);
        try {
            $results = $checks->run();
            $count = array_sum(array_column($results, 'count'));
            DB::table('pos_tenant_integrity_runs')->where('id', $id)->update([
                'finished_at' => now(), 'status' => $count === 0 ? 'clean' : 'violations',
                'violation_count' => $count, 'checks' => json_encode($results, JSON_THROW_ON_ERROR),
            ]);
            Log::log($count === 0 ? 'info' : 'error', 'POS tenant integrity check', ['run_id' => $id, 'checks' => $results]);
            foreach ($results as $name => $result) {
                $this->line($name.': '.$result['count']);
            }

            return $count === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            DB::table('pos_tenant_integrity_runs')->where('id', $id)->update(['finished_at' => now(), 'status' => 'error']);
            Log::error('POS tenant integrity check failed', ['run_id' => $id, 'exception_class' => $error::class]);
            $this->error('Integrity check failed. Run '.$id.' requires investigation.');

            return self::FAILURE;
        }
    }
}
