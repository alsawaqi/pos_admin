<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LAUNCH-P4 A2 — VAT registration set during onboarding gives the merchant
 * its VAT row.
 *
 * When admin marks a company VAT-registered (vat_registered_at set) and the
 * company has no tax row, this creates "VAT / ضريبة القيمة المضافة" at 5%,
 * active — the Omani standard rate. A company that already has a tax row
 * keeps its own taxes: the merchant manages them on the Taxes page. A
 * soft-deleted row called "VAT" (tax names are unique per company, deleted
 * rows included) is brought back as the active 5% VAT instead of a second
 * row. Advertiser-only companies never sell, so they get no tax row.
 *
 * Returns the tax row id it created or restored, or null when it did nothing.
 */
final readonly class EnsureCompanyVatTaxAction
{
    public const NAME = 'VAT';

    public const NAME_AR = 'ضريبة القيمة المضافة';

    public const RATE_PERCENT = '5.00';

    public function __construct(private WriteAuditLogAction $writeAuditLog) {}

    public function handle(Company $company, ?User $actor = null): ?int
    {
        if ($company->vat_registered_at === null || (bool) ($company->is_advertiser_only ?? false)) {
            return null;
        }

        return DB::transaction(function () use ($company, $actor): ?int {
            if (DB::table('pos_taxes')->where('company_id', $company->id)->whereNull('deleted_at')->lockForUpdate()->exists()) {
                return null;
            }

            $now = now();
            $deleted = DB::table('pos_taxes')->where('company_id', $company->id)->where('name', self::NAME)->first();
            if ($deleted !== null) {
                DB::table('pos_taxes')->where('id', $deleted->id)->update([
                    'name_ar' => self::NAME_AR, 'rate_percent' => self::RATE_PERCENT, 'is_active' => true,
                    'deleted_at' => null, 'updated_at' => $now,
                ]);
                $taxId = (int) $deleted->id;
            } else {
                $taxId = (int) DB::table('pos_taxes')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => self::NAME,
                    'name_ar' => self::NAME_AR, 'rate_percent' => self::RATE_PERCENT, 'is_active' => true,
                    'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.vat_tax.created',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: Company::class,
                auditableId: $company->id,
                newValues: ['tax_id' => $taxId, 'name' => self::NAME, 'rate_percent' => self::RATE_PERCENT, 'restored' => $deleted !== null],
            ));

            return $taxId;
        });
    }
}
