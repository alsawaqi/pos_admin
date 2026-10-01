<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use App\Enums\CompanyStatus;
use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyStatusHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Owner decision 2026-10-01 (LAUNCH-P1 P1-19): a merchant's FIRST
 * activation needs a CR certificate AND an owner ID card on file and
 * VERIFIED (follow-up: re-activations do not, {@see requiredFor()}).
 * This class is the single source of that rule: the status transition
 * refuses a first Active while {@see missing()} is non-empty, and the
 * admin merchant page renders {@see checklist()}.
 *
 * A verified document whose expiry date has passed no longer counts.
 * Soft-deleted documents never count (the default model scope).
 */
final class MerchantActivationRequirements
{
    /**
     * @return list<DocumentType>
     */
    public static function requiredTypes(): array
    {
        return [DocumentType::CrCertificate, DocumentType::OwnerIdCard];
    }

    public static function label(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::CrCertificate => 'CR certificate',
            DocumentType::OwnerIdCard => 'Owner ID card',
            default => ucwords(str_replace('_', ' ', $type->value)),
        };
    }

    /**
     * One row per required document type.
     *
     * status: verified | pending | rejected | expired | missing
     *
     * @return list<array{type: string, label: string, status: string, satisfied: bool}>
     */
    public static function checklist(Company $company): array
    {
        $today = Carbon::today();

        /** @var Collection<int, CompanyDocument> $documents */
        $documents = CompanyDocument::query()
            ->where('company_id', $company->id)
            ->whereIn('document_type', array_map(static fn (DocumentType $t): string => $t->value, self::requiredTypes()))
            ->get();

        $rows = [];
        foreach (self::requiredTypes() as $type) {
            $ofType = $documents->filter(static fn (CompanyDocument $d): bool => $d->document_type === $type);

            $validVerified = $ofType->contains(static fn (CompanyDocument $d): bool => $d->verification_status === DocumentVerificationStatus::Verified
                && ($d->expires_at === null || $d->expires_at->gte($today)));

            $status = match (true) {
                $validVerified => 'verified',
                $ofType->contains(static fn (CompanyDocument $d): bool => $d->verification_status === DocumentVerificationStatus::Pending) => 'pending',
                $ofType->contains(static fn (CompanyDocument $d): bool => $d->verification_status === DocumentVerificationStatus::Expired
                    || $d->verification_status === DocumentVerificationStatus::Verified) => 'expired',
                $ofType->contains(static fn (CompanyDocument $d): bool => $d->verification_status === DocumentVerificationStatus::Rejected) => 'rejected',
                default => 'missing',
            };

            $rows[] = [
                'type' => $type->value,
                'label' => self::label($type),
                'status' => $status,
                'satisfied' => $validVerified,
            ];
        }

        return $rows;
    }

    /**
     * Owner follow-up 2026-10-01: the documents are required for a
     * merchant's FIRST activation only. Lifting a suspension (or
     * re-activating a merchant that was live before) does not ask for
     * them again.
     *
     * "Was active before" is true when either holds:
     *  - activated_at is set (stamped on the first transition to Active);
     *  - the status history has a row that moved it to Active (covers
     *    merchants created directly as active before P1-19).
     * Being suspended says nothing: an onboarding merchant can be
     * suspended too (review finding), and its first activation still
     * needs the documents.
     */
    public static function wasActiveBefore(Company $company): bool
    {
        if ($company->activated_at !== null) {
            return true;
        }

        return CompanyStatusHistory::query()
            ->where('company_id', $company->id)
            ->where('to_status', CompanyStatus::Active->value)
            ->exists();
    }

    /**
     * Does making this merchant Active need the verified documents?
     */
    public static function requiredFor(Company $company): bool
    {
        return ! self::wasActiveBefore($company);
    }

    /**
     * @return list<array{type: string, label: string, status: string, satisfied: bool}>
     */
    public static function missing(Company $company): array
    {
        return array_values(array_filter(
            self::checklist($company),
            static fn (array $row): bool => ! $row['satisfied'],
        ));
    }
}
