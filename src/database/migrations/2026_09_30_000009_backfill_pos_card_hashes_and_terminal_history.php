<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pos_qr_sessions')->where('origin', 'table_card')->whereNull('table_qr_token_hash')
            ->whereNull('closed_at')->where('expires_at', '>', now())
            ->orderBy('id')->chunkById(200, function ($sessions): void {
                foreach ($sessions as $session) {
                    $table = DB::table('pos_tables')->where('id', $session->table_id)->where('company_id', $session->company_id)->first();
                    if ($table !== null && filled($table->qr_token)) {
                        DB::table('pos_qr_sessions')->where('id', $session->id)
                            ->update(['table_qr_token_hash' => hash('sha256', $table->qr_token)]);
                    }
                }
            });
        // Payments retain the original merchant even after their device moves.
        $groups = [];
        foreach (DB::table('pos_payments as p')->join('pos_orders as o', 'o.id', '=', 'p.order_id')
            ->whereNotNull('p.bank_id')->whereNotNull('p.terminal_id')
            ->select('p.bank_id', 'p.terminal_id', 'o.company_id')->distinct()->cursor() as $row) {
            $terminal = strtoupper(preg_replace('/\s+/', '', trim($row->terminal_id)));
            if ($terminal === '') {
                continue;
            }
            $key = $row->bank_id.':'.$terminal;
            $groups[$key]['bank'] = $row->bank_id;
            $groups[$key]['terminal'] = $terminal;
            $groups[$key]['companies'][(int) $row->company_id] = true;
        }
        foreach ($groups as $group) {
            $prior = DB::table('pos_terminal_reservations')->where('bank_id', $group['bank'])
                ->where('terminal_id', $group['terminal'])->first();
            if ($prior !== null) {
                continue;
            } // Current reservation already requires an audited transfer.
            // Zero is a deliberate unresolved historical owner, never a tenant FK.
            // Multiple historical owners require explicit Super Admin transfer review.
            $company = count($group['companies']) === 1 ? array_key_first($group['companies']) : 0;
            DB::table('pos_terminal_reservations')->insert([
                'bank_id' => $group['bank'], 'terminal_id' => $group['terminal'], 'company_id' => $company,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Card proof and terminal ownership remain valid after rollback.
    }
};
