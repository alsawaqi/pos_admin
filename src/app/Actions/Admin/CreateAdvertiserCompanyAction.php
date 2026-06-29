<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\CreateCompanyData;
use App\Models\Advertiser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Onboard a brand-new *advertising-only* company together with its marketing
 * portal login, in one transaction.
 *
 * The wizard mirrors merchant onboarding — company info, owners, business
 * activities — minus the commission step. The company is written to the same
 * pos_companies table as a merchant (so trade name / CR / owners / activities
 * all live in one place and the business activity can drive slider filtration),
 * but flagged `is_advertiser_only` so it never appears in the Merchants list or
 * the device fan-out. The {@see Advertiser} login links to it via company_id
 * with is_merchant = false (the company is NOT a POS merchant).
 *
 * Reuses {@see CreateCompanyAction} + {@see CreateAdvertiserAction} verbatim so
 * the company + advertiser are built, audit-logged, and validated exactly the
 * way the merchant and plain-advertiser flows already are.
 */
final readonly class CreateAdvertiserCompanyAction
{
    public function __construct(
        private CreateCompanyAction $createCompany,
        private CreateAdvertiserAction $createAdvertiser,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated payload: the company
     *                                       subset (name, compliance, contact,
     *                                       owners, activities) plus an
     *                                       `account` sub-object with the login.
     * @return array{company: Company, advertiser: Advertiser}
     */
    public function handle(array $data, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            // Build the company exactly like the merchant wizard does (proven
            // snake_case → DTO mapping), then flag it advertiser-only.
            $companyData = CreateCompanyData::from(Arr::only($data, [
                'name', 'name_ar', 'legal_name', 'legal_name_ar',
                'compliance', 'contact', 'owners', 'activities',
            ]));

            $company = $this->createCompany->handle($companyData, $actor);
            $company->update(['is_advertiser_only' => true]);

            /** @var array<string, mixed> $account */
            $account = $data['account'];
            /** @var array<int, array<string, mixed>> $owners */
            $owners = $data['owners'] ?? [];
            /** @var array<string, mixed> $contact */
            $contact = $data['contact'] ?? [];

            // Sensible fallbacks so the admin only has to type email + password:
            // the contact name comes from the primary owner, the brand from the
            // trade name, the phone from the company contact.
            $primaryOwner = collect($owners)->firstWhere('is_primary', true) ?? Arr::first($owners);

            $advertiser = $this->createAdvertiser->handle([
                'name' => $account['contact_name']
                    ?? ($contact['name'] ?? null)
                    ?? ($primaryOwner['full_name_en'] ?? null)
                    ?? $data['name'],
                'brand_name' => $account['brand_name'] ?? $data['name'],
                'email' => $account['email'],
                'password' => $account['password'],
                'phone' => $account['phone'] ?? ($contact['phone'] ?? null),
                'is_merchant' => false,
                'company_id' => $company->id,
                'category' => $account['category'] ?? null,
            ], $actor);

            return ['company' => $company, 'advertiser' => $advertiser];
        });
    }
}
