<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects guests away from the POS admin dashboard', function (): void {
    $this->get('/admin')->assertRedirect('/login');
});

it('renders the POS admin dashboard shell for authenticated admins', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/admin')->assertOk();
});

it('renders the POS admin login shell for guests', function (): void {
    $this->get('/login')->assertOk();
});

it('redirects authenticated users away from the login shell', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/login')->assertRedirect('/admin');
});
