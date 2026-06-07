<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One element of the owners[] array submitted with a Create or
 * Update merchant call. The wizard collects N of these — the
 * front-end's "Add owner" button just pushes another empty one into
 * the array.
 *
 * Exactly one item in the array must have `isPrimary === true`. The
 * FormRequest enforces this so the wizard cannot accidentally
 * submit a company with two primary owners or none.
 *
 * The {@see MapName} attribute lets the API accept snake_case keys
 * (`full_name_en`, `civil_id`) while the PHP DTO exposes camelCase
 * properties (`fullNameEn`, `civilId`).
 */
#[MapName(SnakeCaseMapper::class)]
final class CompanyOwnerData extends Data
{
    public function __construct(
        // Owner full name in English — the only hard requirement.
        public readonly string $fullNameEn,

        // Bilingual + ID fields. All optional.
        public readonly ?string $fullNameAr = null,
        public readonly ?string $civilId = null,

        // ISO-2 country code (e.g. "OM"). Two chars validated by the
        // FormRequest.
        public readonly ?string $nationality = null,

        public readonly ?string $phone = null,
        public readonly ?string $email = null,

        // True for the canonical owner (the person of record for the
        // company). Defaulted to false so the front-end has to flip
        // exactly one entry.
        public readonly bool $isPrimary = false,

        // Optional 0.00–100.00 ownership stake. Sum across owners is
        // NOT enforced — leaving blank is fine, and a company may
        // legitimately have unequal partners that don't add to 100.
        public readonly ?float $ownershipPercentage = null,
    ) {}
}
