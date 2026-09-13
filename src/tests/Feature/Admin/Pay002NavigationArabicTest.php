<?php

declare(strict_types=1);

it('translates both card terminal navigation labels to Arabic', function (): void {
    $labels = json_decode(file_get_contents(resource_path('js/locales/ar.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($labels['nav']['card_terminal_apps'])->toBe('تطبيقات أجهزة الدفع بالبطاقة');
    expect($labels['nav']['card_terminal_issues'])->toBe('مشكلات أجهزة الدفع بالبطاقة');
});
