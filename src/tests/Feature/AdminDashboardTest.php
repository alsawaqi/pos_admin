<?php

declare(strict_types=1);

it('renders the POS admin dashboard shell', function (): void {
    $this->get('/admin')->assertOk();
});

it('renders the POS admin login shell', function (): void {
    $this->get('/login')->assertOk();
});
