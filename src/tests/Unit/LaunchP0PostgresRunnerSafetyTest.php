<?php

it('fix1 runner creates only unique owned databases and never drops an existing schema', function () {
    $runner = file_get_contents(getenv('P0_RUNNER_SOURCE') ?: __DIR__.'/../Support/run-launch-p0.php');
    expect($runner)->toContain('launch_p0_fix1_')
        ->not->toContain('DROP SCHEMA')
        ->not->toContain('DROP DATABASE')
        ->not->toContain("['qr_fix4_p0', 'qrfix2_test', 'qr_fix4_fix5', 'qr_fix6_disposable']");
});
