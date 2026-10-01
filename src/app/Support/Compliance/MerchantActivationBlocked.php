<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use DomainException;

/**
 * Thrown when a merchant would become Active without its required,
 * verified documents (LAUNCH-P1 P1-19). Carries the checklist rows that
 * are not satisfied so the API can list exactly what is missing.
 */
final class MerchantActivationBlocked extends DomainException
{
    /**
     * @param  list<array{type: string, label: string, status: string, satisfied: bool}>  $missing
     */
    public function __construct(public readonly array $missing)
    {
        $parts = array_map(
            static fn (array $row): string => $row['label'].' ('.self::describe($row['status']).')',
            $missing,
        );

        parent::__construct('This merchant cannot be made Active yet. Verified documents are still needed: '.implode(', ', $parts).'.');
    }

    private static function describe(string $status): string
    {
        return match ($status) {
            'pending' => 'uploaded, waiting for verification',
            'rejected' => 'rejected, upload a new one',
            'expired' => 'expired, upload a current one',
            default => 'not uploaded',
        };
    }
}
