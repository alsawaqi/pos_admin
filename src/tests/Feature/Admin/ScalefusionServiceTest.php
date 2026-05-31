<?php

declare(strict_types=1);

/**
 * Unit-ish coverage for the device-detail + control methods on
 * ScalefusionService (ported from the charity ScalefusionController).
 * Focus: the exact scalefusion endpoints + the device_ids[] form/query
 * encoding the v1 API requires.
 */

use App\Services\ScalefusionService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.scalefusion.token' => 'test-token',
        'services.scalefusion.base_v3' => 'https://scalefusion.test/api/v3',
        'services.scalefusion.base_v1' => 'https://scalefusion.test/api/v1',
    ]);
});

it('fetches a single device detail from the v3 api', function (): void {
    Http::fake([
        'scalefusion.test/api/v3/devices/123.json' => Http::response(['device' => ['id' => 123, 'total_ram_size' => 4096]]),
    ]);

    $result = app(ScalefusionService::class)->getDevice(123);

    expect($result['ok'])->toBeTrue();
    expect($result['status'])->toBe(200);
    expect(data_get($result, 'data.device.total_ram_size'))->toBe(4096);
    Http::assertSent(fn ($req) => $req->method() === 'GET' && str_contains($req->url(), '/api/v3/devices/123.json'));
});

it('reboots a device via a v1 PUT', function (): void {
    Http::fake(['*' => Http::response(['success' => true])]);

    $result = app(ScalefusionService::class)->reboot(123);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($req) => $req->method() === 'PUT' && str_contains($req->url(), '/api/v1/devices/123/reboot.json'));
});

it('locks devices with the device_ids[] form encoding scalefusion expects', function (): void {
    Http::fake(['*' => Http::response(['success' => true])]);

    app(ScalefusionService::class)->lock([11, 22]);

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && str_contains($req->url(), '/api/v1/devices/lock.json')
            && str_contains((string) $req->body(), 'device_ids%5B%5D=11')
            && str_contains((string) $req->body(), 'device_ids%5B%5D=22');
    });
});

it('runs a destructive action with device_ids[] in the query + action_type in the body', function (): void {
    Http::fake(['*' => Http::response(['success' => true])]);

    app(ScalefusionService::class)->runAction(123, 'factory_reset', ['wipe_sd_card' => true]);

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && str_contains($req->url(), '/api/v1/devices/actions.json?device_ids%5B%5D=123')
            && str_contains((string) $req->body(), 'action_type=factory_reset')
            && str_contains((string) $req->body(), 'wipe_sd_card=true');
    });
});

it('normalises device location route points oldest-first and drops coordinate-less rows', function (): void {
    Http::fake([
        'scalefusion.test/api/v1/devices/123/locations.json*' => Http::response([
            ['latitude' => 23.6, 'longitude' => 58.5, 'date_time' => 200, 'address' => 'B'],
            ['latitude' => 23.5, 'longitude' => 58.4, 'date_time' => 100, 'address' => 'A'],
            ['latitude' => null, 'longitude' => null, 'date_time' => 150, 'address' => 'no-coords'],
        ]),
    ]);

    $result = app(ScalefusionService::class)->getDeviceLocations(123, '2026-06-16');

    expect($result['ok'])->toBeTrue();
    expect($result['data'])->toHaveCount(2);
    expect($result['data'][0]['address'])->toBe('A');
    expect($result['data'][1]['address'])->toBe('B');
});

it('degrades to ok=false when the token is missing instead of calling out', function (): void {
    config(['services.scalefusion.token' => null]);
    Http::fake();

    $result = app(ScalefusionService::class)->reboot(123);

    expect($result['ok'])->toBeFalse();
    Http::assertNothingSent();
});
