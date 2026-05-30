<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test/seed factory for {@see Device}. Defaults reflect the most
 * common shape of a freshly-registered fixed POS terminal that has
 * not yet been assigned to a branch.
 *
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // UUID exposed in the URL, never the auto-increment id.
            'uuid' => (string) Str::uuid(),

            // Two human-meaningful identifiers: the physical serial
            // printed on the device, and the scalefusion kiosk id we
            // pair against. Both unique in production but the factory
            // randomises both to avoid collision in parallel tests.
            'serial_number' => strtoupper(fake()->bothify('POS-####-????')),
            'kiosk_id' => 'KIOSK-'.strtoupper(fake()->bothify('????-#####')),

            // The internal admin label (free text — "POS-001",
            // "HAND-A03"). Blueprint §4.4.2.
            'name' => 'POS Terminal',
            'label' => null,

            // Hardware make/model FKs. Default null so tests that
            // don't care can stay terse; the
            // 2026_05_25_010100 migration made these nullable for
            // the same reason.
            'make_id' => null,
            'model_id' => null,

            // Defaults to the most common class. Tests that need
            // handheld/tablet behaviour can pass ->state(['device_type' => DeviceType::Handheld]).
            'device_type' => DeviceType::FixedPos,

            // Fresh registration: the device exists in our DB but has
            // not yet been assigned to a branch.
            'status' => DeviceStatus::Registered,

            // Empty metadata bag — used by scalefusion adapter for any
            // unstructured payload we don't have a dedicated column for.
            'metadata' => [],

            // Fresh registration has no soft-POS terminal yet — bank +
            // terminal are set when the device is ASSIGNED (see assigned()).
            'bank_id' => null,
            'terminal_id' => null,
        ];
    }

    /**
     * A device that has been assigned to a merchant: it carries a bank +
     * terminal_id and sits in the Assigned state. Company/branch are left to
     * the caller (pass them explicitly) so the factory doesn't invent a
     * tenant graph it doesn't need.
     */
    public function assigned(): static
    {
        return $this->state(fn (): array => [
            'terminal_id' => 'TID-'.strtoupper(fake()->unique()->bothify('######')),
            'status' => DeviceStatus::Assigned,
            'assigned_at' => now(),
        ]);
    }
}
