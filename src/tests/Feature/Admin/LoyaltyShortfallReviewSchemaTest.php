<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provides a separate resolution trail without changing the loyalty ledger', function (): void {
    expect(Schema::hasTable('pos_loyalty_shortfall_reviews'))->toBeTrue()
        ->and(Schema::hasColumns('pos_loyalty_shortfall_reviews', [
            'uuid',
            'company_id',
            'loyalty_transaction_id',
            'resolved_by_user_id',
            'resolution_note',
            'resolved_at',
            'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('pos_loyalty_transactions', 'review_status'))->toBeFalse();
});
