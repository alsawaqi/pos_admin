<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentType: string
{
    case CrCertificate = 'cr_certificate';
    case VatCertificate = 'vat_certificate';
    case MunicipalityLicense = 'municipality_license';
    case ChamberCertificate = 'chamber_certificate';
    case OwnerIdCard = 'owner_id_card';
    case LeaseAgreement = 'lease_agreement';
    case SignatureAuthority = 'signature_authority';
    case BankLetter = 'bank_letter';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function tracksExpiry(): bool
    {
        return match ($this) {
            self::CrCertificate,
            self::VatCertificate,
            self::MunicipalityLicense,
            self::ChamberCertificate,
            self::OwnerIdCard,
            self::LeaseAgreement => true,
            default => false,
        };
    }
}
