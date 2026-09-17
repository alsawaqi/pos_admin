<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('keeps audience measurement in the Overview card with merchant-only permissions', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Admin/Merchants/Show.vue'));

    expect($source)->toBeString();
    assert(is_string($source));

    $policyCard = Str::between(
        $source,
        '<!-- QR-002 S5: POS policies',
        '<!-- /QR-002 S5: POS policies -->',
    );

    expect($policyCard)
        ->toContain('v-if="can(PlatformPermission.MerchantsView)"')
        ->toContain('data-testid="merchant-pos-policies"')
        ->toContain('data-testid="audience-measurement-policy"')
        ->not->toContain('data-testid="dine-in-round-mode-policy"')
        ->not->toContain('data-testid="dine-in-round-mode-value"')
        ->toContain("t('merchants.audience.title')")
        ->not->toContain("t('merchants.pos_policies.round_mode.title')")
        ->not->toContain('PlatformPermission.DevicesView');

    expect(substr_count($policyCard, '!can(PlatformPermission.MerchantsUpdate)'))->toBe(1);
    expect($source)->not->toContain('updateMerchantDineInRoundMode');
    expect($policyCard)->not->toContain('dine-in-round-mode-select');

    $devicesTab = Str::between(
        $source,
        '<section v-if="activeTab === \'devices\'"',
        '<section v-if="activeTab === \'portal_users\'"',
    );

    expect($devicesTab)
        ->not->toContain('data-testid="merchant-pos-policies"')
        ->not->toContain('data-testid="audience-measurement-policy"')
        ->not->toContain("t('merchants.audience.title')");
});

it('does not render or request the merchant-owned round mode from admin', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Admin/Merchants/Show.vue'));
    $api = file_get_contents(resource_path('js/lib/api/merchants.ts'));

    expect($source)->toBeString();
    expect($api)->toBeString();
    expect($source)
        ->not->toContain('dine-in-round-mode')
        ->not->toContain('dineInRoundMode')
        ->not->toContain('getMerchantDineInRoundMode')
        ->not->toContain('fetchDineInRoundMode')
        ->toContain('await fetchAudienceMeasurement();');
    expect($api)
        ->not->toContain('DineInRoundMode')
        ->not->toContain('/dine-in-round-mode');
});

it('loads merchant policies independently of the Devices tab', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Admin/Merchants/Show.vue'));

    expect($source)->toBeString();
    assert(is_string($source));

    $merchantFetcher = Str::between(
        $source,
        'async function fetchMerchant',
        'async function fetchDocuments',
    );
    $devicesFetcher = Str::between(
        $source,
        'async function fetchDevicesForTab',
        '// ---- Merchant POS policies',
    );

    expect($merchantFetcher)->toContain('void fetchPosPolicies();');
    expect($devicesFetcher)
        ->not->toContain('fetchPosPolicies')
        ->not->toContain('fetchAudienceMeasurement');
});

it('provides English and Arabic copy for the retained POS policy labels', function (): void {
    $paths = [
        'title',
        'subtitle',
        'load_failed',
        'save_failed',
    ];

    $translations = [];
    foreach (['en', 'ar'] as $locale) {
        $json = file_get_contents(resource_path("js/locales/{$locale}.json"));
        expect($json)->toBeString();
        assert(is_string($json));

        $translations[$locale] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        expect(data_get($translations[$locale], 'merchants.pos_policies.round_mode'))->toBeNull();
        foreach ($paths as $path) {
            expect(data_get($translations[$locale], "merchants.pos_policies.{$path}"))
                ->toBeString()
                ->not->toBe('');
        }
    }

    foreach ($paths as $path) {
        expect(data_get($translations, "ar.merchants.pos_policies.{$path}"))
            ->not->toBe(data_get($translations, "en.merchants.pos_policies.{$path}"));
    }
});
