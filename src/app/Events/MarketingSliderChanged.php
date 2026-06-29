<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 3 — real-time slider refresh. Fired when an admin creates / updates /
 * deletes a marketing slider. Broadcast (inline) on the SHARED Reverb to the
 * affected device branch channels (private-branch.{id} — the same channels
 * pos_api's DeviceSyncBroadcast uses). The device's generic live-sync handler
 * turns ANY event on its channel into a /device/config re-sync (sliders ride
 * full), so the customer-screen ad loop refreshes within seconds — no manual
 * refresh / re-login.
 */
final class MarketingSliderChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param  list<int>  $branchIds  branches whose devices should refresh */
    public function __construct(public readonly array $branchIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (int $id): PrivateChannel => new PrivateChannel("branch.{$id}"),
            $this->branchIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'marketing.sliders.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['type' => 'marketing.sliders.changed'];
    }
}
