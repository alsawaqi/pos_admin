<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 part A — admin SPA contracts for the frontend-only parts of
 * the fixes (same source-contract style as MerchantPosPoliciesUiContractTest).
 * The behaviour behind each one is covered by the API tests in this folder.
 */

function p1Source(string $relative): string
{
    $source = file_get_contents(resource_path('js/'.$relative));
    expect($source)->toBeString();

    return (string) $source;
}

/**
 * @return array<string, mixed>
 */
function p1Locale(string $lang): array
{
    return json_decode((string) file_get_contents(resource_path("js/locales/{$lang}.json")), true, flags: JSON_THROW_ON_ERROR);
}

it('P1-2: shows a copy-once set-password link instead of a generated password', function (): void {
    $show = p1Source('Pages/Admin/Merchants/Show.vue');
    $team = p1Source('Pages/Admin/Team/Index.vue');
    $dialog = p1Source('Components/Admin/SetPasswordLinkDialog.vue');

    expect($show)->toContain('<SetPasswordLinkDialog')->not->toContain('plaintext_password');
    expect($team)->toContain('<SetPasswordLinkDialog')->not->toContain('plaintext_password');
    expect(p1Source('lib/api/portalUsers.ts'))->toContain('set_password_link')->not->toContain('plaintext_password');
    expect($dialog)->toContain('data-testid="copy-set-password-link"')
        ->toContain("t('set_password_link.copy')")
        ->toContain('link.expires_at');
    expect(p1Locale('en')['set_password_link']['copy'])->toBe('Copy set-password link');
    expect(p1Locale('ar')['set_password_link'])->toHaveKey('copy');
});

it('P1-14: lets the admin create the first login without a branch or device', function (): void {
    $show = p1Source('Pages/Admin/Merchants/Show.vue');

    expect($show)->toContain('const canInvite = computed(() => merchant.value !== null);')
        ->not->toContain('devicesCount > 0');
});

it('P1-8: offers forgot / set / reset password pages and a change-password card', function (): void {
    $router = p1Source('router.ts');

    expect($router)->toContain("path: '/forgot-password'")
        ->toContain("path: '/reset-password'")
        ->toContain("path: '/set-password'");
    expect(p1Source('Pages/Auth/Login.vue'))->toContain('to="/forgot-password"')->not->toContain('href="#"');
    expect(p1Source('Pages/Admin/Security.vue'))->toContain('data-testid="change-password-card"')
        ->toContain("apiPost('/auth/change-password'");
});

it('P1-15: holds an admin without two-step login on the setup page', function (): void {
    expect(p1Source('router.ts'))->toContain('twoFactorSetupRequired()')
        ->toContain("name: 'admin.security', query: { setup: 'required' }");
    expect(p1Source('Pages/Admin/Security.vue'))->toContain('data-testid="two-factor-setup-required"');
    expect(p1Source('Pages/Admin/Team/Index.vue'))->toContain('data-testid="reset-two-factor"')
        ->toContain('resetPlatformUserTwoFactor');
});

it('P1-19: shows the required-document checklist and blocks Activate until it is met', function (): void {
    $show = p1Source('Pages/Admin/Merchants/Show.vue');

    expect($show)->toContain('data-testid="activation-checklist"')
        ->toContain("(transitionForm.target === 'active' && !activationReady)");
});

it('P1-21: shows the contact phone in the merchant overview', function (): void {
    expect(p1Source('Pages/Admin/Merchants/Show.vue'))->toContain('data-testid="merchant-contact-phone"')
        ->toContain('merchant.contact.phone');
});

it('P1-22: does not label activated devices Online in the status chart', function (): void {
    $dashboard = p1Source('Pages/Admin/Dashboard.vue');
    $segments = (string) str($dashboard)->between('function deviceDonutSegments', "\n}");

    expect($segments)->toContain("t('dashboard.device_status.active')")
        ->not->toContain('devices.status_options');
    expect(p1Locale('en')['dashboard']['device_status']['active'])->toBe('Activated')
        ->and(p1Locale('ar')['dashboard']['device_status']['active'])->not->toBe(p1Locale('ar')['devices']['status_options']['active']);
});

it('asks for confirmation before force-logging-out a user', function (): void {
    $component = p1Source('Components/Admin/ForceUserLogout.vue');

    expect($component)->toContain('<ConfirmDialog')
        ->toContain('@click="confirming = true"')
        ->toContain('@confirm="forceLogout"')
        ->not->toContain('@click="forceLogout"');
});

it('links every merchant wizard label to its input', function (): void {
    $wizard = p1Source('Pages/Admin/Merchants/Create.vue');

    preg_match_all('/<label\b[^>]*class="block text-sm font-semibold text-slate-700"[^>]*>/', $wizard, $labels);
    expect($labels[0])->not->toBeEmpty();
    foreach ($labels[0] as $label) {
        expect($label)->toMatch('/\s:?for="/');
    }

    preg_match_all('/\s:?for="([^"]+)"/', implode("\n", $labels[0]), $fors);
    foreach ($fors[1] as $target) {
        expect($wizard)->toContain('id="'.$target.'"');
    }
});

it('P1-18: refuses to leave the activities step without exactly one primary activity', function (): void {
    expect(p1Source('Pages/Admin/Merchants/Create.vue'))
        ->toContain("t('merchants.errors.activity_required')")
        ->toContain("t('merchants.errors.activity_one_primary')");
});

it('P1-17: never pre-fills the Muscat pin and keeps the radius at 500-2000 m', function (): void {
    $modal = p1Source('Components/Admin/BranchFormModal.vue');

    expect($modal)->not->toContain('?? 23.5859')
        ->toContain('min="500" max="2000"')
        ->toContain("t('branches.form.location_required')");
});
